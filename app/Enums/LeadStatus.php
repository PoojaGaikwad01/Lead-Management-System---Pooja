<?php

namespace App\Enums;

enum LeadStatus: string
{
    case New = 'new';
    case NeedsReview = 'needs_review';
    case Assigned = 'assigned';
    case ReplySent = 'reply_sent';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::NeedsReview => 'Needs review',
            self::Assigned => 'Assigned',
            self::ReplySent => 'Reply sent',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'bg-sky-100 text-sky-700',
            self::NeedsReview => 'bg-amber-100 text-amber-800',
            self::Assigned => 'bg-violet-100 text-violet-700',
            self::ReplySent => 'bg-emerald-100 text-emerald-700',
            self::Closed => 'bg-slate-100 text-slate-500',
        };
    }
}
