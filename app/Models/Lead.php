<?php

namespace App\Models;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Concerns\BelongsToBranch;
use App\Services\Google\GoogleLeadProcessor;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'source', 'external_id', 'contact_id', 'name', 'phone', 'email', 'city',
    'branch_id', 'assigned_by', 'assigned_at', 'replied_at', 'status',
    'campaign_name', 'ad_name', 'form_name', 'agency_name', 'raw_payload', 'last_activity_at',
])]
class Lead extends Model
{
    use BelongsToBranch, HasFactory;

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'status' => LeadStatus::class,
            'raw_payload' => 'array',
            'assigned_at' => 'datetime',
            'replied_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** Message channels where the customer talks to us directly (excludes 'lead_ad', 'agency', 'note'). */
    public const CHAT_CHANNELS = ['whatsapp', 'messenger', 'instagram'];

    /**
     * Who/where a free-text reply goes, and whether the 24h customer-service window is open.
     * Based on the customer's latest inbound chat message — never on agency forwards or lead-ad summaries.
     *
     * @return array{channel: ?string, recipient: ?string, open: bool, closes_at: ?CarbonInterface}
     */
    public function replyWindow(): array
    {
        $last = ($this->relationLoaded('messages') ? $this->messages : $this->messages()->get())
            ->filter(fn (Message $m) => $m->isInbound() && in_array($m->channel, self::CHAT_CHANNELS, true))
            ->last();

        $channel = $last?->channel;
        $recipient = match ($channel) {
            'whatsapp' => $this->phone ? ltrim($this->phone, '+') : null,
            'messenger', 'instagram' => $this->contact_id,
            default => null,
        };
        $closesAt = $last?->created_at->copy()->addHours(24);

        return [
            'channel' => $channel,
            'recipient' => $recipient,
            'open' => $recipient !== null && $closesAt !== null && $closesAt->isFuture(),
            'closes_at' => $closesAt,
        ];
    }

    /** Assign or reassign to a branch; the new branch owes a fresh reply. */
    public function assignTo(Branch $branch, User $by): void
    {
        $this->forceFill([
            'branch_id' => $branch->id,
            'assigned_by' => $by->id,
            'assigned_at' => now(),
            'replied_at' => null,
            'status' => LeadStatus::Assigned,
        ])->save();
    }

    /** Status → reply_sent; replied_at keeps the FIRST reply since assignment. */
    public function markReplied(): void
    {
        $this->forceFill(['status' => LeadStatus::ReplySent, 'replied_at' => $this->replied_at ?? now()])->save();
    }

    protected static function booted(): void
    {
        static::creating(fn (Lead $lead) => $lead->last_activity_at ??= $lead->created_at ?? now());
    }

    // --- Relations ---

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('created_at');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    // --- Attributes ---

    /** Stored normalized (+91…) when recognisable, otherwise as typed so admin can fix it. */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $v) => Phone::normalize($v) ?? (filled($v) ? trim($v) : null));
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->name ?: ($this->phone ?: 'Unknown '.$this->source->short().' lead'));
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->name) {
                return '#';
            }

            return collect(preg_split('/\s+/', trim($this->name)))->take(2)
                ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->join('');
        });
    }

    /**
     * Lead form answers as [['name' => …, 'value' => …]] — Meta (field_data) or Google (user_column_data).
     */
    public function formAnswers(): array
    {
        $payload = $this->raw_payload ?? [];

        if (isset($payload['user_column_data'])) {
            return GoogleLeadProcessor::answerRows($payload);
        }

        return collect($payload['field_data'] ?? [])
            ->map(fn ($f) => ['name' => ucfirst(str_replace('_', ' ', $f['name'] ?? '')), 'value' => implode(', ', (array) ($f['values'] ?? []))])
            ->all();
    }

    /** Google "Send test data" leads (is_test = true in the webhook body). */
    public function isTest(): bool
    {
        return $this->source === LeadSource::GoogleLeadAd && filter_var($this->raw_payload['is_test'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    // --- Scopes ---

    /** Inbox filters: q (name/phone), source, status, branch_id ("none" = unassigned). */
    public function scopeFilter(Builder $query, array $f): void
    {
        $query
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['branch_id'] ?? null, fn ($q, $v) => $v === 'none' ? $q->whereNull('branch_id') : $q->where('branch_id', (int) $v))
            ->when(trim($f['q'] ?? ''), function ($q, $term) {
                $digits = ltrim(preg_replace('/\D/', '', $term), '0');
                // whereLike = case-insensitive on every driver (ILIKE on PostgreSQL).
                $q->where(fn ($w) => $w->whereLike('name', "%{$term}%")
                    ->when(strlen($digits) >= 3, fn ($w) => $w->orWhereLike('phone', "%{$digits}%")));
            });
    }
}
