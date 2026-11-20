<?php

// All lead-system settings. Read via config('leads.*'), never env() in app code.
return [

    // mock = no real Meta/WhatsApp/FCM/AI calls; live = real APIs.
    'mode' => env('APP_MODE', 'mock'),

    'dedupe_days' => (int) env('DEDUPE_DAYS', 7),
    'reminder_after_minutes' => (int) env('REMINDER_AFTER_MINUTES', 60),

    // WhatsApp senders treated as agencies (e.g. 919876543210, 9876543210 or +14155551234). Compared via Phone::normalize().
    'agency_numbers' => array_values(array_filter(array_map(
        fn ($n) => preg_replace('/[^\d+]/', '', $n),
        explode(',', (string) env('AGENCY_NUMBERS', ''))
    ))),

    'meta' => [
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'app_secret' => env('META_APP_SECRET'),
        'verify_token' => env('META_VERIFY_TOKEN'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
        'ig_account_id' => env('IG_ACCOUNT_ID'),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WA_PHONE_NUMBER_ID'),
        'access_token' => env('WA_ACCESS_TOKEN'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'none'),
        'api_key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL'),
    ],

    'firebase' => [
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
    ],
];
