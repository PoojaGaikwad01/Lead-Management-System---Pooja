<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Attachments live on the private disk; only users who can view the lead may download them. */
class AttachmentController extends Controller
{
    public function show(Message $message)
    {
        Gate::authorize('view', $message->lead);
        abort_unless($path = $message->attachment_url, 404);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return redirect()->away($path); // Messenger/IG CDN link
        }

        abort_unless(Storage::exists($path), 404);

        return Storage::download($path, basename($path));
    }
}
