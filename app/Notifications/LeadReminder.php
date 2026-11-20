<?php

namespace App\Notifications;

use App\Models\Lead;
use App\Notifications\Channels\FcmChannel;
use App\Services\Push\FcmClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** In-app bell (database) always; FCM push too when Firebase credentials exist and the user has devices. */
class LeadReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead, public bool $automatic = false) {}

    public function via(object $notifiable): array
    {
        return app(FcmClient::class)->configured() && $notifiable->deviceTokens()->exists()
            ? ['database', FcmChannel::class]
            : ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'title' => 'Reply pending: '.$this->lead->display_name,
            'body' => $this->lead->source->label().' lead assigned '.($this->lead->assigned_at?->diffForHumans() ?? 'earlier').'. Please reply.',
            'url' => route('inbox.show', $this->lead),
            'automatic' => $this->automatic,
        ];
    }

    public function toFcm(object $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
