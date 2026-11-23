<?php

namespace App\Services\Google;

use App\Enums\LeadSource;
use App\Models\Lead;
use App\Models\Message;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fake Google Ads lead form webhook bodies in Google's exact shape, for the "Send sample Google lead"
 * button, `php artisan google:send-sample` and the mock seeder. Edit the form questions here to match the real form.
 */
class SampleGoogleLead
{
    public const CAMPAIGN_ID = 21987654321;

    public const FORM_ID = 40123456789;

    private const FIRST = ['Aarav', 'Priya', 'Rohan', 'Sneha', 'Vikram', 'Ananya', 'Aditya', 'Pooja', 'Sahil', 'Neha', 'Omkar', 'Kavya', 'Rahul', 'Shruti', 'Tejas', 'Rutuja'];

    private const LAST = ['Patil', 'Deshmukh', 'Kulkarni', 'Joshi', 'Shinde', 'Pawar', 'Jadhav', 'More', 'Sharma', 'Mehta', 'Chavan', 'Bhosale'];

    private const CITIES = ['Nashik', 'Nashik', 'Pune', 'Pune', 'Mumbai', 'Mumbai', 'Thane', 'Nagpur'];

    private const SERVICES = ['Website Development', 'Mobile App Development', 'Digital Marketing', 'SEO', 'Custom Software / CRM'];

    private const CALL_TIMES = ['Morning (9–12)', 'Afternoon (12–4)', 'Evening (4–8)', 'Any time'];

    /**
     * @param  array  $person  overrides: name, phone, email, city, service, call_time
     */
    public static function payload(array $person = [], ?CarbonInterface $at = null, bool $isTest = false, ?string $leadId = null): array
    {
        $first = Str::before($person['name'] ?? '', ' ') ?: self::pick(self::FIRST);
        $last = Str::after($person['name'] ?? '', ' ') ?: self::pick(self::LAST);

        return [
            'lead_id' => $leadId ?? GoogleLeadProcessor::SAMPLE_PREFIX.Str::random(40),
            'user_column_data' => [
                ['column_name' => 'Full Name', 'string_value' => $person['name'] ?? "{$first} {$last}", 'column_id' => 'FULL_NAME'],
                ['column_name' => 'User Phone', 'string_value' => $person['phone'] ?? '+91'.self::pick(['98', '97', '96', '90', '88', '82', '79', '70']).random_int(10000000, 99999999), 'column_id' => 'PHONE_NUMBER'],
                ['column_name' => 'User Email', 'string_value' => $person['email'] ?? strtolower("{$first}.{$last}").random_int(1, 99).'@gmail.com', 'column_id' => 'EMAIL'],
                ['column_name' => 'City', 'string_value' => $person['city'] ?? self::pick(self::CITIES), 'column_id' => 'CITY'],
                ['column_name' => 'Which service are you interested in?', 'string_value' => $person['service'] ?? self::pick(self::SERVICES), 'column_id' => 'SERVICE'],
                ['column_name' => 'Best time to call you', 'string_value' => $person['call_time'] ?? self::pick(self::CALL_TIMES), 'column_id' => 'PREFERRED_CONTACT_TIME'],
            ],
            'api_version' => '1.0',
            'form_id' => self::FORM_ID,
            'campaign_id' => self::CAMPAIGN_ID,
            'google_key' => (string) config('leads.google.lead_key'),
            'is_test' => $isTest,
            'gcl_id' => Str::random(64),
            'adgroup_id' => self::CAMPAIGN_ID + 1000 + random_int(1, 9),
            'creative_id' => 700000000000 + random_int(1000, 9999),
            'lead_submit_time' => ($at ?? now())->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Delete every sample lead (lead_id "sample-…") and sample form summaries merged into other leads.
     * Real Google leads are never touched.
     */
    public static function clear(): int
    {
        $prefix = GoogleLeadProcessor::SAMPLE_PREFIX;

        return DB::transaction(function () use ($prefix) {
            Message::where('external_message_id', 'like', "leadgen:{$prefix}%")->delete();

            return Lead::where('source', LeadSource::GoogleLeadAd)->where('external_id', 'like', "{$prefix}%")->delete();
        });
    }

    /** The body as it travels over HTTP. */
    public static function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function pick(array $items): string
    {
        return $items[array_rand($items)];
    }
}
