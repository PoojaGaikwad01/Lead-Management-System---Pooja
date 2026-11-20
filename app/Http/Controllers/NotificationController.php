<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/** In-app notification bell. */
class NotificationController extends Controller
{
    public function open(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $leadId = $notification->data['lead_id'] ?? null;

        return redirect($leadId ? route('inbox.show', $leadId) : route('inbox'));
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['count' => $request->user()->unreadNotifications()->count()]);
    }
}
