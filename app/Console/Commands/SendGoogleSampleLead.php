<?php

namespace App\Console\Commands;

use App\Services\Google\SampleGoogleLead;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Tests the full HTTP path exactly as Google Ads calls it: POST JSON → key check → lead → 200 {}. */
class SendGoogleSampleLead extends Command
{
    protected $signature = 'google:send-sample
        {--url= : Endpoint (default: APP_URL/webhooks/google-leads), e.g. the Render URL}
        {--count=1 : How many leads to send}
        {--test : Flag them is_test, like Google Ads "Send test data"}
        {--key= : Send this google_key instead of GOOGLE_LEAD_KEY (try a wrong one to see the 401)}';

    protected $description = 'POST fake Google Ads lead form leads (Google\'s exact JSON format) to the webhook';

    public function handle(): int
    {
        $url = $this->option('url') ?: rtrim((string) config('app.url'), '/').'/webhooks/google-leads';
        $count = max(1, min(50, (int) $this->option('count')));

        for ($i = 0; $i < $count; $i++) {
            $payload = SampleGoogleLead::payload(isTest: (bool) $this->option('test'));
            if ($this->option('key') !== null) {
                $payload['google_key'] = (string) $this->option('key');
            }

            try {
                $response = Http::withBody(SampleGoogleLead::json($payload), 'application/json')->timeout(30)->post($url);
            } catch (ConnectionException) {
                $this->error("Could not reach {$url} — is the app running?");

                return self::FAILURE;
            }

            $line = "POST {$url} -> HTTP {$response->status()} {$response->body()}  ({$payload['user_column_data'][0]['string_value']})";
            $response->successful() ? $this->info($line) : $this->error($line);
        }

        return self::SUCCESS;
    }
}
