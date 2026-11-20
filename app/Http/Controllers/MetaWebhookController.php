<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaWebhook;
use Illuminate\Http\Request;

/** One endpoint for Page (leadgen + Messenger), Instagram and WhatsApp webhooks. */
class MetaWebhookController extends Controller
{
    /** Subscription handshake. PHP turns "hub.mode" into "hub_mode". */
    public function verify(Request $request)
    {
        $token = (string) config('leads.meta.verify_token');

        abort_unless(
            $request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token')),
            403
        );

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    /** Verify signature, queue, and answer 200 immediately. */
    public function receive(Request $request)
    {
        abort_unless($this->hasValidSignature($request), 403, 'Invalid signature');

        if ($payload = $request->json()->all()) {
            ProcessMetaWebhook::dispatch($payload);
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function hasValidSignature(Request $request): bool
    {
        $secret = (string) config('leads.meta.app_secret');

        if ($secret === '') {
            return config('leads.mode') !== 'live'; // mock mode may run without keys; live never does
        }

        return hash_equals(
            'sha256='.hash_hmac('sha256', $request->getContent(), $secret),
            (string) $request->header('X-Hub-Signature-256')
        );
    }
}
