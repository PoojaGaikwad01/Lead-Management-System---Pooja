<?php

namespace App\Services\Google;

use App\Enums\LeadSource;
use App\Models\Lead;
use App\Services\LeadIngestor;
use Illuminate\Support\Str;

/**
 * Google Ads lead form webhook body → lead, via LeadIngestor::ingestLeadAd() (same path as Meta Lead Ads:
 * idempotent on lead_id, a duplicate phone within DEDUPE_DAYS becomes a 'lead_ad' message on the existing lead).
 *
 * Format (developers.google.com/google-ads/webhook/docs/implementation):
 *   lead_id, user_column_data[{column_id, string_value, column_name?}], api_version, form_id, campaign_id,
 *   adgroup_id, creative_id, asset_group_id (int64), google_key, is_test, gcl_id, lead_stage, lead_submit_time.
 * Unknown fields are ignored (kept in raw_payload). google_key is never stored.
 */
class GoogleLeadProcessor
{
    /** lead_id prefix of leads made by the "Send sample Google lead" button / google:send-sample. */
    public const SAMPLE_PREFIX = 'sample-';

    public function __construct(private LeadIngestor $ingestor) {}

    /** Raw body → array. 64-bit ids too big for a PHP int stay strings. Null when not a JSON object. */
    public static function decode(string $body): ?array
    {
        $data = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }

    /** Returns the new lead (or the duplicate it was merged into); null if this lead_id was already processed. */
    public function process(array $payload): ?Lead
    {
        $attributes = self::attributes($payload);
        $formName = $attributes['form_name'];

        $summary = '📝 Submitted '.LeadSource::GoogleLeadAd->label().($formName ? " “{$formName}”" : '')."\n"
            .collect(self::answerRows($payload))->map(fn ($row) => "{$row['name']}: {$row['value']}")->join("\n");

        return $this->ingestor->ingestLeadAd(LeadSource::GoogleLeadAd, (string) $payload['lead_id'], $attributes, $summary);
    }

    /** Lead attributes for a payload (no source/external_id/status — the ingestor sets those). */
    public static function attributes(array $payload): array
    {
        $a = self::answers($payload);
        $name = $a['FULL_NAME'] ?? trim(($a['FIRST_NAME'] ?? '').' '.($a['LAST_NAME'] ?? ''));
        $email = $a['EMAIL'] ?? $a['WORK_EMAIL'] ?? null;
        [$campaign, $form, $adGroup] = [self::id($payload['campaign_id'] ?? null), self::id($payload['form_id'] ?? null), self::id($payload['adgroup_id'] ?? null)];

        $raw = $payload;
        unset($raw['google_key']);

        return [
            'name' => $name !== '' ? $name : null,
            'phone' => $a['PHONE_NUMBER'] ?? $a['WORK_PHONE'] ?? null,
            'email' => $email ? strtolower($email) : null,
            'city' => $a['CITY'] ?? null,
            // The webhook only carries ids (names need the Google Ads API).
            'campaign_name' => $campaign ? "Google campaign {$campaign}" : null,
            'ad_name' => $adGroup ? "Ad group {$adGroup}" : null,
            'form_name' => $form ? "Google form {$form}" : null,
            'raw_payload' => $raw,
        ];
    }

    /** Answers keyed by column_id, e.g. ['FULL_NAME' => 'Priya Sharma', 'CITY' => 'Nashik']. */
    public static function answers(array $payload): array
    {
        $answers = [];
        foreach ($payload['user_column_data'] ?? [] as $column) {
            if (! is_array($column)) {
                continue;
            }
            $id = strtoupper(trim((string) ($column['column_id'] ?? '')));
            $value = trim((string) ($column['string_value'] ?? ''));
            if ($id !== '' && $value !== '' && ! isset($answers[$id])) {
                $answers[$id] = $value;
            }
        }

        return $answers;
    }

    /** Every answer in form order, labelled with the question text when Google sends it. */
    public static function answerRows(array $payload): array
    {
        return collect($payload['user_column_data'] ?? [])
            ->filter(fn ($column) => is_array($column) && filled($column['string_value'] ?? null))
            ->map(fn (array $column) => [
                'name' => filled($column['column_name'] ?? null)
                    ? (string) $column['column_name']
                    : Str::of((string) ($column['column_id'] ?? ''))->lower()->replace('_', ' ')->ucfirst()->toString(),
                'value' => (string) $column['string_value'],
            ])
            ->values()->all();
    }

    /** int64 id → string, whether Google sent a JSON number or a quoted string. */
    private static function id(mixed $value): ?string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) && $value !== '' => $value,
            is_float($value) => sprintf('%.0f', $value),
            default => null,
        };
    }
}
