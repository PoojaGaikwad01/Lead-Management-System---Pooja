<?php

namespace App\Notifications\Channels;

use App\Services\Push\FcmClient;
use Illuminate\Notifications\Notification;

/** Push to every registered device of the notifiable; prunes tokens FCM reports as unregistered. */
class FcmChannel
{
    public function __construct(private FcmClient $fcm) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $msg = $notification->toFcm($notifiable);

        foreach ($notifiable->deviceTokens as $device) {
            try {
                if (! $this->fcm->send($device->token, $msg['title'], $msg['body'], ['url' => $msg['url'], 'lead_id' => (string) $msg['lead_id']])) {
                    $device->delete();
                }
            } catch (\Throwable $e) {
                report($e); // one bad device must not block the others or the in-app notification
            }
        }
    }
}
