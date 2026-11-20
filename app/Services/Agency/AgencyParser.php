<?php

namespace App\Services\Agency;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Services\Agency\Ai\AiLeadExtractor;
use App\Services\LeadIngestor;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an agency's WhatsApp message into leads:
 *  - Excel/CSV attachment → one lead per row (flexible headers)
 *  - free text → one lead per phone number (regex + "Name:"/"City:" labels), optional AI pass when unclear
 *  - nothing usable → one "needs review" lead holding the original message.
 * Each lead gets its own copy of the message (external_message_id "{wamid}#n", channel "agency") so the original stays visible.
 */
class AgencyParser
{
    public function __construct(
        private LeadIngestor $ingestor,
        private TextLeadExtractor $text,
        private SpreadsheetLeadReader $sheet,
        private AiLeadExtractor $ai,
    ) {}

    /**
     * @param  array{id: string, from: string, agency_name: ?string, text: ?string, attachment_url: ?string, filename: ?string, mime: ?string, created_at: \Carbon\CarbonInterface}  $msg
     */
    public function handle(array $msg): void
    {
        if ($this->ingestor->alreadyProcessed($msg['id'])) {
            return;
        }

        $candidates = $this->candidates($msg);

        DB::transaction(function () use ($msg, $candidates) {
            if (! $candidates) {
                $this->store($msg, $msg['id'], ['name' => null, 'phone' => null, 'city' => null, 'ambiguous' => true]);

                return;
            }
            foreach (array_values($candidates) as $n => $candidate) {
                $this->store($msg, $msg['id'].'#'.($n + 1), $candidate);
            }
        });
    }

    private function store(array $msg, string $id, array $c): void
    {
        $this->ingestor->store(
            $c['phone'] ? $this->ingestor->findDuplicate($c['phone']) : null,
            [
                'source' => LeadSource::WhatsappAgency,
                'external_id' => $id,
                'agency_name' => $msg['agency_name'],
                'name' => $c['name'],
                'phone' => $c['phone'],
                'city' => $c['city'],
                'status' => $c['phone'] && ! $c['ambiguous'] ? LeadStatus::New : LeadStatus::NeedsReview,
                'raw_payload' => ['from' => $msg['from'], 'filename' => $msg['filename'], 'parsed' => array_diff_key($c, ['body' => 1])],
            ],
            [
                'external_message_id' => $id,
                'channel' => 'agency', // from the agency, not the customer: never opens the 24h reply window
                'body' => $c['body'] ?? $msg['text'],
                'attachment_url' => $msg['attachment_url'],
                'created_at' => $msg['created_at'],
            ],
        );
    }

    private function candidates(array $msg): array
    {
        $path = $msg['attachment_url'];

        if ($path && SpreadsheetLeadReader::supports($msg['filename'], $msg['mime']) && Storage::exists($path)) {
            try {
                $rows = $this->sheet->read(Storage::path($path))['rows'];
            } catch (\Throwable $e) {
                Log::warning('Agency spreadsheet could not be read', ['message' => $msg['id'], 'error' => $e->getMessage()]);

                return [];
            }

            return array_map(fn ($r) => $r + ['body' => $this->rowBody($msg, $r)], $rows);
        }

        $text = trim((string) $msg['text']);
        if ($text === '') {
            return [];
        }

        $found = $this->text->extract($text);

        // Optional AI pass only when the rules weren't sure. AI_PROVIDER=none → returns null → skipped.
        if (! $found || collect($found)->contains('ambiguous', true)) {
            $ai = collect($this->ai->extract($text) ?? [])
                ->map(fn ($c) => ['name' => $c['name'] ?? null, 'phone' => Phone::normalize($c['phone'] ?? null), 'city' => $c['city'] ?? null, 'ambiguous' => false])
                ->filter(fn ($c) => $c['phone'])
                ->values()->all();
            $found = $ai ?: $found;
        }

        return $found;
    }

    private function rowBody(array $msg, array $row): string
    {
        $lines = collect($row['cells'])->map(fn ($v, $k) => "{$k}: {$v}")->join("\n");

        return "Row {$row['row']} of ".($msg['filename'] ?: 'spreadsheet').":\n{$lines}";
    }
}
