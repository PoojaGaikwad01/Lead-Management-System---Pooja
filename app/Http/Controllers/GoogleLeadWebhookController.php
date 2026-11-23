<?php

namespace App\Http\Controllers;

use App\Services\Google\GoogleLeadProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/google-leads — the "Webhook URL" in the Google Ads lead form.
 * Google expects 200 "{}"; a 4xx with {"message"} is not retried, a 5xx is.
 * Processed synchronously: the body already contains the whole lead (no API call needed).
 */
class GoogleLeadWebhookController extends Controller
{
    public function __invoke(Request $request, GoogleLeadProcessor $processor): JsonResponse
    {
        $payload = GoogleLeadProcessor::decode($request->getContent());
        if ($payload === null) {
            return response()->json(['message' => 'Body must be a JSON object.'], 400);
        }

        if (! $this->hasValidKey($payload['google_key'] ?? null)) {
            Log::channel('meta')->warning('Google lead rejected: invalid google_key', ['key_configured' => filled(config('leads.google.lead_key'))]);

            return response()->json(['message' => 'Invalid google_key.'], 401);
        }

        $leadId = $payload['lead_id'] ?? null;
        if (! is_string($leadId) || trim($leadId) === '' || strlen($leadId) > 255) {
            return response()->json(['message' => 'lead_id is required.'], 400);
        }

        $lead = $processor->process($payload);

        // Ids only — never names, phone numbers or answers.
        Log::channel('meta')->info('Google lead received', [
            'lead_id' => $leadId,
            'lead' => $lead?->id,
            'duplicate_delivery' => $lead === null,
            'is_test' => (bool) ($payload['is_test'] ?? false),
        ]);

        return response()->json(new \stdClass);
    }

    /** Same rule as the Meta signature: mock mode may run without a key; live never does. */
    private function hasValidKey(mixed $given): bool
    {
        $expected = (string) config('leads.google.lead_key');

        if ($expected === '') {
            return config('leads.mode') !== 'live';
        }

        return is_string($given) && hash_equals($expected, $given);
    }
}
