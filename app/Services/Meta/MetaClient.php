<?php

namespace App\Services\Meta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Thin Graph API client. In APP_MODE=mock it never calls Meta:
 * Graph objects come from tests/fixtures/meta/graph/{id}.json, media from tests/fixtures/meta/media/{filename}.
 * Simulated leads (LeadSimulator) put their Graph objects / media in the cache first (MOCK_GRAPH_KEY / MOCK_MEDIA_KEY).
 */
class MetaClient
{
    public const MOCK_GRAPH_KEY = 'meta.mock.graph.';

    public const MOCK_MEDIA_KEY = 'meta.mock.media.';

    public function isMock(): bool
    {
        return config('leads.mode') !== 'live';
    }

    /** GET a Graph object by id. */
    public function get(string $id, array $query = [], ?string $token = null): array
    {
        if ($this->isMock()) {
            if (is_array($simulated = Cache::get(self::MOCK_GRAPH_KEY.$id))) {
                return $simulated;
            }
            $file = base_path('tests/fixtures/meta/graph/'.basename($id).'.json');

            return is_file($file) ? json_decode(file_get_contents($file), true) : [];
        }

        return Http::baseUrl('https://graph.facebook.com/'.config('leads.meta.graph_version'))
            ->withToken($token ?? config('leads.meta.page_access_token'))
            ->timeout(15)
            ->retry(2, 300)
            ->get($id, $query)
            ->json() ?? [];
    }

    public function getLead(string $leadgenId): array
    {
        return $this->get($leadgenId, ['fields' => 'id,created_time,field_data,ad_id,ad_name,adset_name,campaign_name,form_id,platform']);
    }

    public function getFormName(?string $formId): ?string
    {
        if (! $formId) {
            return null;
        }

        return Cache::remember("meta.form.{$formId}", now()->addDay(), fn () => $this->get($formId, ['fields' => 'name'])['name'] ?? null);
    }

    /** Messenger / Instagram sender's display name. Never throws (profile access may be restricted). */
    public function getProfileName(string $userId, string $channel): ?string
    {
        try {
            $d = $this->get($userId, ['fields' => $channel === 'instagram' ? 'name,username' : 'first_name,last_name']);
        } catch (\Throwable $e) {
            Log::warning('Meta profile lookup failed', ['id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        return ($d['name'] ?? null) ?: (trim(($d['first_name'] ?? '').' '.($d['last_name'] ?? '')) ?: ($d['username'] ?? null));
    }

    /**
     * Send a free-text reply. Returns the platform message id. Throws on API errors.
     * whatsapp → Cloud API /{WA_PHONE_NUMBER_ID}/messages (recipient = phone digits)
     * messenger / instagram → /me/messages with the Page token (recipient = PSID / IG-scoped id)
     */
    public function sendText(string $channel, string $recipient, string $text): string
    {
        if ($this->isMock()) {
            return 'mock.out.'.Str::uuid();
        }

        $http = Http::baseUrl('https://graph.facebook.com/'.config('leads.meta.graph_version'))->timeout(20)->acceptJson();

        $response = match ($channel) {
            'whatsapp' => $http->withToken(config('leads.whatsapp.access_token'))
                ->post(config('leads.whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => ['preview_url' => false, 'body' => $text],
                ]),
            'messenger', 'instagram' => $http->withToken(config('leads.meta.page_access_token'))
                ->post('me/messages', [
                    'recipient' => ['id' => $recipient],
                    'messaging_type' => 'RESPONSE',
                    'message' => ['text' => $text],
                ]),
            default => throw new \InvalidArgumentException("Cannot send on channel '{$channel}'"),
        };

        $response->throw();

        return $response->json('messages.0.id') ?? $response->json('message_id') ?? throw new \RuntimeException('No message id in API response');
    }

    /**
     * WhatsApp media: GET /{media_id} -> url -> download (WA token). Stores on the private disk.
     * Returns the storage path, e.g. "media/whatsapp/{media_id}/leads.xlsx".
     */
    public function downloadWhatsAppMedia(string $mediaId, ?string $filename = null, ?string $mime = null): string
    {
        $filename = Str::of($filename ?: 'file.'.Str::afterLast((string) $mime, '/'))->basename()->replaceMatches('/[^\w.\- ]/', '_')->toString();
        $path = 'media/whatsapp/'.Str::slug($mediaId, '_').'/'.$filename;

        if ($this->isMock()) {
            $fixture = base_path('tests/fixtures/meta/media/'.$filename);
            $simulated = Cache::get(self::MOCK_MEDIA_KEY.$mediaId);
            Storage::put($path, match (true) {
                is_string($simulated) => base64_decode($simulated),
                is_file($fixture) => file_get_contents($fixture),
                default => "Mock media {$mediaId}",
            });

            return $path;
        }

        $token = config('leads.whatsapp.access_token');
        $url = $this->get($mediaId, [], $token)['url'] ?? throw new \RuntimeException("No URL for WhatsApp media {$mediaId}");
        Storage::put($path, Http::withToken($token)->timeout(60)->retry(2, 500)->get($url)->body());

        return $path;
    }
}
