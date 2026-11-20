<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Firebase Cloud Messaging HTTP v1 without an SDK: signs a service-account JWT (RS256) for an OAuth token.
 * Active only when FIREBASE_CREDENTIALS_PATH points to a file. In mock mode nothing is sent (logged only).
 */
class FcmClient
{
    public function configured(): bool
    {
        $path = config('leads.firebase.credentials_path');

        return filled($path) && is_file($path);
    }

    /** Returns false when the device token is no longer registered (caller should delete it). */
    public function send(string $token, string $title, string $body, array $data = []): bool
    {
        if (config('leads.mode') !== 'live') {
            Log::info('FCM (mock): push not sent', ['title' => $title, 'token' => Str::limit($token, 12)]);

            return true;
        }

        $credentials = $this->credentials();
        $response = Http::withToken($this->accessToken($credentials))
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", ['message' => array_filter([
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
                'webpush' => isset($data['url']) ? ['fcm_options' => ['link' => $data['url']]] : null,
            ])]);

        if ($response->status() === 404 || str_contains($response->body(), 'UNREGISTERED')) {
            return false;
        }
        $response->throw();

        return true;
    }

    private function credentials(): array
    {
        return json_decode(file_get_contents(config('leads.firebase.credentials_path')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function accessToken(array $c): string
    {
        return Cache::remember('fcm.access_token', now()->addMinutes(50), function () use ($c) {
            $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
            $tokenUri = $c['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();

            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode([
                'iss' => $c['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
            openssl_sign($unsigned, $signature, $c['private_key'], OPENSSL_ALGO_SHA256) || throw new \RuntimeException('Could not sign the FCM JWT');

            return Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.$b64($signature),
            ])->throw()->json('access_token');
        });
    }
}
