<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For any model with a branch_id column (leads, etc.).
 * Use Model::visibleTo($user) everywhere a Branch Manager could read data.
 */
trait BelongsToBranch
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->isAdmin()) {
            $query->where($this->qualifyColumn('branch_id'), $user->branch_id ?? 0);
        }
    }
}
