<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'direction', 'channel', 'body', 'attachment_url', 'external_message_id', 'sent_by_user_id', 'created_at'])]
class Message extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Keep the lead's inbox sort key in step with its newest message.
        static::created(function (Message $m) {
            Lead::whereKey($m->lead_id)
                ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $m->created_at))
                ->update(['last_activity_at' => $m->created_at]);
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === 'in';
    }
}
