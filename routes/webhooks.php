<?php

use App\Http\Controllers\GoogleLeadWebhookController;
use App\Http\Controllers\MetaWebhookController;
use Illuminate\Support\Facades\Route;

// Loaded outside the "web" group (bootstrap/app.php): no session, cookies or CSRF.
Route::get('webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('webhooks/meta', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');

// Google Ads lead forms (google_key in the body is checked instead of a signature).
Route::post('webhooks/google-leads', GoogleLeadWebhookController::class)->name('webhooks.google-leads');
