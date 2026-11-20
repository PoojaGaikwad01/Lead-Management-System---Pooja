<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\Request;

/**
 * FCM device registration for the signed-in user (session auth; send X-CSRF-TOKEN from the page's meta tag).
 * POST /device-tokens {token, platform: android|ios|web}   DELETE /device-tokens {token}
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'in:android,ios,web'],
        ]);

        // A token belongs to one device: re-registering moves it to the current user.
        $device = DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? null],
        );

        return response()->json(['id' => $device->id, 'registered' => true], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request)
    {
        $token = $request->validate(['token' => ['required', 'string']])['token'];

        $request->user()->deviceTokens()->where('token', $token)->delete();

        return response()->noContent();
    }
}
