<?php

namespace App\Services\Simulation;

use App\Enums\LeadSource;
use App\Jobs\ProcessMetaWebhook;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Google\SampleGoogleLead;
use App\Services\Meta\MetaClient;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Simulation mode (APP_MODE=mock, until the real Meta / WhatsApp details are available).
 *
 * run() builds a fresh webhook body in the platform's exact format and sends it through the real pipeline
 * (ProcessMetaWebhook → MetaWebhookProcessor → LeadIngestor / AgencyParser), so switching to live changes nothing
 * downstream. What the real flow fetches from Meta (lead form answers, profile names, media files) is put in the
 * cache, where MetaClient reads it in mock mode. Every simulated id starts with "sim-", so clear() never touches
 * real leads. Google Ads leads are simulated by SampleGoogleLead (the browser posts them to the real webhook).
 *
 * Content: Idyllic Services (IT services). Edit the lists below to change it.
 */
class LeadSimulator
{
    public const PREFIX = 'sim-';

    /** Agency number used when AGENCY_NUMBERS is empty (same as .env.example). */
    public const AGENCY_NUMBER = '+919900000001';

    /** Menu entries: type => [lead source, label]. */
    public const TYPES = [
        'fb_lead_ad' => [LeadSource::FbLeadAd, 'Facebook lead form'],
        'ig_lead_ad' => [LeadSource::IgLeadAd, 'Instagram lead form'],
        'messenger' => [LeadSource::Messenger, 'Messenger chat'],
        'ig_dm' => [LeadSource::IgDm, 'Instagram DM'],
        'whatsapp' => [LeadSource::WhatsappCustomer, 'WhatsApp chat'],
        'agency_text' => [LeadSource::WhatsappAgency, 'Agency message'],
        'agency_excel' => [LeadSource::WhatsappAgency, 'Agency Excel file'],
    ];

    private const FIRST = ['Aarav', 'Priya', 'Rohan', 'Sneha', 'Vikram', 'Ananya', 'Aditya', 'Pooja', 'Sahil', 'Neha', 'Omkar', 'Kavya', 'Rahul', 'Shruti', 'Tejas', 'Rutuja', 'Nikhil', 'Isha'];

    private const LAST = ['Patil', 'Deshmukh', 'Kulkarni', 'Joshi', 'Shinde', 'Pawar', 'Jadhav', 'More', 'Sharma', 'Mehta', 'Chavan', 'Bhosale', 'Gaikwad', 'Wagh'];

    private const CITIES = ['Nashik', 'Nashik', 'Pune', 'Pune', 'Mumbai', 'Thane', 'Nagpur', 'Aurangabad'];

    private const SERVICES = ['Website Development', 'Mobile App Development', 'Digital Marketing', 'SEO', 'Custom Software / CRM', 'E-commerce Website'];

    private const BUDGETS = ['Under ₹50,000', '₹50,000 – ₹2 lakh', '₹2 lakh – ₹5 lakh', 'Above ₹5 lakh'];

    private const BUSINESSES = ['restaurant', 'dental clinic', 'coaching classes', 'real estate agency', 'clothing brand', 'CA firm', 'gym', 'hotel', 'school', 'pharmacy'];

    private const CHATS = [
        'Hi, I need a website for my {business}. What would it cost?',
        'Hello! Do you build mobile apps for Android and iOS? Need one for my {business}.',
        'Do you provide SEO services in {city}? Our {business} website is not showing on Google.',
        'We need a CRM for our sales team of about {n} people. Can you share a quote?',
        'Our {business} website is very old. Can you redesign it?',
        'I want to start an online store for my {business}. Can we discuss?',
        'Do you run Facebook and Instagram ads? Looking for more enquiries for my {business}.',
    ];

    /** Lead ad forms (ids are stable so the form name cache stays valid). */
    private const FORMS = [
        ['id' => 'sim-form-1', 'name' => 'Free Website Consultation', 'campaign' => 'Website Development – Leads', 'ads' => ['Carousel – Our Work', 'Reel – Website in 7 Days']],
        ['id' => 'sim-form-2', 'name' => 'Get a Quote – Apps & CRM', 'campaign' => 'Apps & CRM – Leads', 'ads' => ['Video – Client Story', 'Image – Free Demo']],
        ['id' => 'sim-form-3', 'name' => 'Grow with Digital Marketing', 'campaign' => 'SEO & Digital Marketing – Leads', 'ads' => ['Reel – Rank on Google', 'Carousel – Client Results']],
    ];

    private const AUDIENCES = ['Nashik Business Owners 25-55', 'Pune SMEs', 'Maharashtra Startups', 'Mumbai Retailers'];

    private const AGENCIES = ['Digital Reach Agency', 'LeadBoost Media'];

    /** Simulation needs mock mode: in live mode MetaClient would ask the real Graph API for the fake ids. */
    public static function available(): bool
    {
        return config('leads.mode') !== 'live';
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type][1];
    }

    public static function source(string $type): LeadSource
    {
        return self::TYPES[$type][0];
    }

    /** Simulates one delivery. Returns the lead it created, or the existing lead it was merged into (same phone). */
    public function run(string $type): ?Lead
    {
        $id = self::PREFIX.Str::random(24);

        ProcessMetaWebhook::dispatchSync(match ($type) {
            'fb_lead_ad' => $this->leadAd($id, 'fb'),
            'ig_lead_ad' => $this->leadAd($id, 'ig'),
            'messenger' => $this->messenger($id),
            'ig_dm' => $this->instagram($id),
            'whatsapp' => $this->whatsappCustomer($id),
            'agency_text' => $this->agencyText($id),
            'agency_excel' => $this->agencyExcel($id),
        });

        // Lead ads keep the id as external_id; chats use the first message id; agency leads use "{id}#n".
        return Lead::where('external_id', $id)->orWhere('external_id', 'like', $id.'#%')->orderBy('id')->first()
            ?? Message::whereIn('external_message_id', [$id, "leadgen:{$id}", "{$id}#1"])->first()?->lead;
    }

    /**
     * Deletes simulated leads, simulated messages merged into other leads, their media files and the sample
     * Google leads. Leads whose ids don't start with "sim-" / "sample-" (real ones) are never touched.
     */
    public static function clear(): int
    {
        $count = DB::transaction(function () {
            Message::where('external_message_id', 'like', self::PREFIX.'%')
                ->orWhere('external_message_id', 'like', 'leadgen:'.self::PREFIX.'%')
                ->delete();

            return Lead::where('external_id', 'like', self::PREFIX.'%')->delete(); // their messages/reminders cascade
        }) + SampleGoogleLead::clear();

        // MetaClient stores media under media/whatsapp/{slug of media id}: "sim-media-Ab12" → "sim_media_ab12".
        foreach (Storage::directories('media/whatsapp') as $dir) {
            if (str_starts_with(basename($dir), 'sim_media_')) {
                Storage::deleteDirectory($dir);
            }
        }

        return $count;
    }

    // ---- Facebook / Instagram lead ads: the webhook carries only ids; MetaClient::getLead() returns the answers ----

    private function leadAd(string $id, string $platform): array
    {
        $form = self::pick(self::FORMS);
        $p = $this->person();
        $adId = (string) random_int(120000000000000, 129999999999999);

        $name = $platform === 'ig'
            ? [['name' => 'first_name', 'values' => [$p['first']]], ['name' => 'last_name', 'values' => [$p['last']]]]
            : [['name' => 'full_name', 'values' => [$p['name']]]];

        $this->graph($form['id'], ['id' => $form['id'], 'name' => $form['name']]);
        $this->graph($id, [
            'id' => $id,
            'created_time' => now()->utc()->format('Y-m-d\TH:i:sO'),
            'ad_id' => $adId,
            'ad_name' => self::pick($form['ads']),
            'adset_name' => self::pick(self::AUDIENCES),
            'campaign_name' => $form['campaign'],
            'form_id' => $form['id'],
            'platform' => $platform,
            'field_data' => [
                ...$name,
                ['name' => 'phone_number', 'values' => [$p['phone']]],
                ['name' => 'email', 'values' => [$p['email']]],
                ['name' => 'city', 'values' => [$p['city']]],
                ['name' => 'which_service_are_you_interested_in?', 'values' => [self::pick(self::SERVICES)]],
                ['name' => 'what_is_your_budget?', 'values' => [self::pick(self::BUDGETS)]],
            ],
        ]);

        return ['object' => 'page', 'entry' => [[
            'id' => 'PAGE_ID_SIM',
            'time' => now()->getTimestamp(),
            'changes' => [['field' => 'leadgen', 'value' => [
                'leadgen_id' => $id,
                'page_id' => 'PAGE_ID_SIM',
                'form_id' => $form['id'],
                'ad_id' => $adId,
                'adgroup_id' => (string) random_int(120000000000000, 129999999999999),
                'created_time' => now()->getTimestamp(),
            ]]],
        ]]];
    }

    // ---- Messenger / Instagram DMs: the sender's name comes from MetaClient::getProfileName() ----

    private function messenger(string $id): array
    {
        $p = $this->person();
        $sender = self::PREFIX.random_int(1000000000000000, 9999999999999999);
        $this->graph($sender, ['id' => $sender, 'first_name' => $p['first'], 'last_name' => $p['last']]);

        return ['object' => 'page', 'entry' => [$this->messagingEntry('PAGE_ID_SIM', $sender, $id, $this->chatText($p))]];
    }

    private function instagram(string $id): array
    {
        $p = $this->person();
        $sender = self::PREFIX.random_int(1000000000000000, 9999999999999999);
        $this->graph($sender, ['id' => $sender, 'name' => $p['name'], 'username' => strtolower($p['first'].'.'.$p['last'])]);

        return ['object' => 'instagram', 'entry' => [$this->messagingEntry('IG_ACCOUNT_SIM', $sender, $id, $this->chatText($p))]];
    }

    private function messagingEntry(string $accountId, string $sender, string $mid, string $text): array
    {
        $ms = now()->getTimestampMs();

        return [
            'id' => $accountId,
            'time' => $ms,
            'messaging' => [[
                'sender' => ['id' => $sender],
                'recipient' => ['id' => $accountId],
                'timestamp' => $ms,
                'message' => ['mid' => $mid, 'text' => $text],
            ]],
        ];
    }

    // ---- WhatsApp: customers, and agencies (senders in AGENCY_NUMBERS) forwarding leads as text or Excel ----

    private function whatsappCustomer(string $id): array
    {
        $p = $this->person();

        return $this->whatsapp(ltrim($p['phone'], '+'), $p['name'], $id, ['type' => 'text', 'text' => ['body' => $this->chatText($p)]]);
    }

    private function agencyText(string $id): array
    {
        [$a, $b] = [$this->person(), $this->person()];
        $agency = self::pick(self::AGENCIES);
        $body = "New leads from {$agency}:\n"
            ."1) Name: {$a['name']}, Phone: ".substr($a['phone'], 3).", City: {$a['city']}\n"
            ."2) Name: {$b['name']}\nMobile: {$b['phone']}\nCity: {$b['city']}\n"
            .'Both need '.strtolower(self::pick(self::SERVICES)).' – please call them today.';

        return $this->whatsapp($this->agencyWaId(), $agency, $id, ['type' => 'text', 'text' => ['body' => $body]]);
    }

    private function agencyExcel(string $id): array
    {
        $mediaId = self::PREFIX.'media-'.Str::random(12);
        $filename = 'leads-'.now()->format('d-M').'.xlsx';
        Cache::put(MetaClient::MOCK_MEDIA_KEY.$mediaId, base64_encode($this->spreadsheet(random_int(2, 4))), now()->addHour());

        return $this->whatsapp($this->agencyWaId(), self::pick(self::AGENCIES), $id, ['type' => 'document', 'document' => [
            'id' => $mediaId,
            'filename' => $filename,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'caption' => 'Leads for this week',
        ]]);
    }

    private function whatsapp(string $from, string $profileName, string $id, array $message): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA_SIM',
            'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '919800011111', 'phone_number_id' => 'PHONE_ID_SIM'],
                'contacts' => [['profile' => ['name' => $profileName], 'wa_id' => $from]],
                'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) now()->getTimestamp()] + $message],
            ]]],
        ]]];
    }

    /** First AGENCY_NUMBERS entry; without one, the simulated agency counts as an agency for this request only. */
    private function agencyWaId(): string
    {
        $number = collect(config('leads.agency_numbers'))->map(fn ($n) => Phone::normalize($n))->filter()->first();
        if ($number === null) {
            config(['leads.agency_numbers' => [self::AGENCY_NUMBER]]);
            $number = self::AGENCY_NUMBER;
        }

        return ltrim($number, '+');
    }

    /** An agency sheet: header row + one lead per row, phones written as text the way agencies type them. */
    private function spreadsheet(int $rows): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(['Customer Name', 'Mobile', 'City', 'Requirement']);
        for ($r = 2; $r <= $rows + 1; $r++) {
            $p = $this->person();
            $digits = substr($p['phone'], 3);
            $sheet->fromArray([$p['name'], $r % 2 ? $p['phone'] : substr($digits, 0, 5).' '.substr($digits, 5), $p['city'], self::pick(self::SERVICES)], null, "A{$r}");
        }

        $file = tempnam(sys_get_temp_dir(), 'sim');
        try {
            (new Xlsx($book))->save($file);

            return file_get_contents($file);
        } finally {
            @unlink($file);
        }
    }

    // ---- helpers ----

    /** @return array{first: string, last: string, name: string, phone: string, email: string, city: string, business: string} */
    private function person(): array
    {
        $first = self::pick(self::FIRST);
        $last = self::pick(self::LAST);

        return [
            'first' => $first,
            'last' => $last,
            'name' => "{$first} {$last}",
            'phone' => '+91'.self::pick(['98', '97', '96', '93', '90', '88', '82', '79', '70']).random_int(10000000, 99999999),
            'email' => strtolower("{$first}.{$last}").random_int(1, 99).'@gmail.com',
            'city' => self::pick(self::CITIES),
            'business' => self::pick(self::BUSINESSES),
        ];
    }

    private function chatText(array $p): string
    {
        return strtr(self::pick(self::CHATS), ['{business}' => $p['business'], '{city}' => $p['city'], '{n}' => (string) random_int(5, 40)]);
    }

    private function graph(string $id, array $object): void
    {
        Cache::put(MetaClient::MOCK_GRAPH_KEY.$id, $object, now()->addHour());
    }

    private static function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }
}
