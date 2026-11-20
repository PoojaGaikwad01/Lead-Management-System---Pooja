<?php

namespace Database\Seeders;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

/** 30 mock leads (5 per source) with short conversations. Runs only when the leads table is empty. */
class LeadSeeder extends Seeder
{
    private const NAMES = [
        'Aarav Sharma', 'Diya Kapoor', 'Vihaan Joshi', 'Ananya Iyer', 'Kabir Malhotra', 'Ishita Desai',
        'Reyansh Gupta', 'Myra Nair', 'Aditya Kulkarni', 'Saanvi Reddy', 'Arnav Bhatt', 'Kiara Shah',
        'Vivaan Menon', 'Anika Chopra', 'Rohan Pillai', 'Tara Banerjee', 'Dhruv Agarwal', 'Meera Krishnan',
        'Yash Jain', 'Nisha Verma', 'Karan Singh', 'Riya Saxena', 'Aryan Thakur', 'Sara Fernandes',
        'Neil D\'Souza', 'Avni Mishra', 'Om Patil', 'Zara Khan', 'Ishaan Rao', 'Prisha Mehta',
    ];

    private const CITIES = ['Mumbai', 'Pune', 'Bengaluru', 'Thane', 'Navi Mumbai'];

    // Cycled per lead index.
    private const STATUSES = ['new', 'assigned', 'reply_sent', 'assigned', 'new', 'reply_sent', 'closed', 'assigned'];

    private const CHATS = [
        [['in', 'Hi! Do you host birthday parties? My son turns 5 on 14th Dec.'], ['out', 'Hi! Yes we do 🎉 Party packages start at ₹15,000 for 15 kids. Shall I share the menu?'], ['in', 'Yes please. Is Saturday evening free?']],
        [['in', 'What are your timings on weekends?'], ['out', 'We are open 10am–9pm on Saturday and Sunday.'], ['in', 'Great. Any sibling discount?']],
        [['in', 'Hello, price for 2 hours of play for a 3 year old?'], ['out', '₹699 for 2 hours. Grip socks are mandatory 🧦'], ['in', 'Ok thanks, will visit tomorrow']],
        [['in', 'Can we book the whole place for a school picnic? Around 40 kids'], ['in', 'Need it on a weekday next month'], ['out', 'Absolutely! Please share your preferred date and we will send a quote.']],
    ];

    public function run(): void
    {
        if (Lead::exists()) {
            return;
        }

        $admin = User::where('role', Role::Admin)->first();
        $branches = Branch::orderBy('id')->get();
        $managers = User::where('role', Role::BranchManager)->get()->keyBy('branch_id');
        $sources = LeadSource::cases();

        foreach (self::NAMES as $i => $name) {
            $source = $sources[$i % count($sources)];
            $status = LeadStatus::from(self::STATUSES[$i % count(self::STATUSES)]);
            $at = now()->subHours((29 - $i) * 6 + 1)->subMinutes(($i * 7) % 50)->startOfMinute();
            $phone = '98'.sprintf('%08d', 45123000 + $i * 3171);
            $city = self::CITIES[$i % count(self::CITIES)];

            $lead = new Lead(['source' => $source, 'external_id' => "mock-{$source->value}-{$i}", 'city' => $city]);

            match ($source) {
                LeadSource::FbLeadAd, LeadSource::IgLeadAd => $lead->fill([
                    'name' => $name, 'phone' => $phone,
                    'email' => strtolower(strtok($name, ' ')).$i.'@example.com',
                    'campaign_name' => $i % 2 ? 'Weekend Play Pass – Nov' : 'Birthday Parties – Nov',
                    'ad_name' => $i % 2 ? 'Reel – Play Zone Tour' : 'Carousel – Party Packages',
                    'form_name' => $i % 2 ? 'Play Pass Enquiry' : 'Party Enquiry Form',
                    'raw_payload' => ['id' => "mock-leadgen-{$i}", 'created_time' => $at->toIso8601String(), 'field_data' => [
                        ['name' => 'full_name', 'values' => [$name]],
                        ['name' => 'phone_number', 'values' => ['+91'.$phone]],
                        ['name' => 'city', 'values' => [$city]],
                        ['name' => 'number_of_kids', 'values' => [(string) (10 + $i)]],
                        ['name' => 'preferred_date', 'values' => [$at->copy()->addDays(10 + $i)->format('d M Y')]],
                    ]],
                ]),
                LeadSource::Messenger, LeadSource::IgDm => $lead->fill([
                    'name' => $name,
                    'phone' => $i % 4 === 0 ? $phone : null, // chats often have no phone yet
                    'contact_id' => ($source === LeadSource::Messenger ? 'psid-' : 'igsid-').(100000 + $i),
                    'raw_payload' => ['mock' => true],
                ]),
                LeadSource::WhatsappCustomer => $lead->fill([
                    'name' => $name, 'phone' => $phone, 'contact_id' => '91'.$phone, 'raw_payload' => ['mock' => true],
                ]),
                LeadSource::WhatsappAgency => $lead->fill([
                    'name' => $name, 'phone' => $phone,
                    'agency_name' => $i % 2 ? 'Happy Events Co.' : 'PartyWala Agency',
                    'raw_payload' => ['mock' => true, 'from' => $i % 2 ? '919900000001' : '919900000002'],
                ]),
            };

            // Agency leads #11 and #23: parse was unclear -> needs review, unassigned.
            if (in_array($i, [11, 23], true)) {
                $status = LeadStatus::NeedsReview;
                $lead->fill(['name' => null, 'phone' => $i === 11 ? null : $phone]);
            }

            $branch = in_array($status, [LeadStatus::New, LeadStatus::NeedsReview], true) ? null : $branches[$i % $branches->count()];
            $lead->fill([
                'status' => $status,
                'branch_id' => $branch?->id,
                'assigned_by' => $branch ? $admin?->id : null,
                'assigned_at' => $branch ? $at->copy()->addMinutes(15) : null,
            ]);
            $lead->created_at = $lead->updated_at = $at;
            $lead->save();

            $this->seedMessages($lead, $i, $at, $managers->get($branch?->id), $name, $phone, $city);

            if (in_array($status, [LeadStatus::ReplySent, LeadStatus::Closed], true)) {
                $firstReply = $lead->messages()->where('direction', 'out')->min('created_at');
                $lead->forceFill(['replied_at' => $firstReply ?? $at->copy()->addMinutes(25 + ($i * 13) % 180)])->save();
            }
        }
    }

    private function seedMessages(Lead $lead, int $i, $at, ?User $manager, string $name, string $phone, string $city): void
    {
        $channel = $lead->source->channel();
        if ($channel === null) {
            return; // Lead Ads have no chat thread.
        }

        if ($lead->source === LeadSource::WhatsappAgency) {
            $body = match ($i) {
                11 => "Customer interested in party for 25 kids next month, please call after 6pm.\nFrom PartyWala",
                23 => "Enquiry - kids birthday, Thane side, around 20 kids.\nContact {$phone} / alternate maybe 99XXXXXX12",
                default => "New lead from {$lead->agency_name}\nName: {$name}\nPhone: {$phone}\nCity: {$city}\nRequirement: Birthday party, ~25 kids",
            };
            $chat = [['in', $body]];
        } else {
            $chat = self::CHATS[$i % count(self::CHATS)];
            // Only leads that were replied to have outbound messages; otherwise keep the inbound ones before any reply.
            if (! in_array($lead->status, [LeadStatus::ReplySent, LeadStatus::Closed], true)) {
                $chat = collect($chat)->takeWhile(fn ($m) => $m[0] === 'in')->all();
            }
        }

        foreach (array_values($chat) as $k => [$dir, $body]) {
            Message::create([
                'lead_id' => $lead->id,
                'direction' => $dir,
                'channel' => $lead->source === LeadSource::WhatsappAgency ? 'agency' : $channel,
                'body' => $body,
                'external_message_id' => "mock-msg-{$i}-{$k}",
                'sent_by_user_id' => $dir === 'out' ? $manager?->id : null,
                'created_at' => $at->copy()->addMinutes($k * 9 + ($dir === 'out' ? 30 : 0)),
            ]);
        }
    }
}
