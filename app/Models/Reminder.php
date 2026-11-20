<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['lead_id', 'sent_to_user_id', 'sent_at'])]
class Reminder extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function sentTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_to_user_id');
    }
}
