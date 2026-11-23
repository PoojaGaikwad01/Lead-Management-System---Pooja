<?php

namespace App\Http\Controllers;

use App\Services\Google\SampleGoogleLead;
use Illuminate\Http\JsonResponse;

/**
 * Admin demo tools for Google Ads lead forms (config leads.google.demo_tools, default on in mock mode).
 * Checked per action, not in a constructor, so route:list / route:cache keep working when off.
 */
class GoogleDemoController extends Controller
{
    /**
     * "Send sample Google lead": returns a Google-format webhook body. The browser then POSTs it to
     * /webhooks/google-leads itself — the same request Google makes. (A server-to-self request would
     * hang `php artisan serve`, which handles one request at a time.)
     */
    public function sampleLead(): JsonResponse
    {
        abort_unless(config('leads.google.demo_tools'), 404);

        return response()->json([
            'webhook_url' => route('webhooks.google-leads', absolute: false),
            'body' => SampleGoogleLead::json(SampleGoogleLead::payload()), // JSON text: the browser can't round int64 ids
        ]);
    }

    public function clearSamples()
    {
        abort_unless(config('leads.google.demo_tools'), 404);

        $count = SampleGoogleLead::clear();

        return redirect()->route('inbox')->with('status', "Removed {$count} sample Google ".str('lead')->plural($count).'.');
    }
}
