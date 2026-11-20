<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    /** Admin: any lead. Branch Manager: only leads assigned to their branch. */
    public function view(User $user, Lead $lead): bool
    {
        return $user->isAdmin() || ($lead->branch_id !== null && $lead->branch_id === $user->branch_id);
    }

    /** Reply / mark reply sent / close: anyone who can view it, once it's assigned to a branch. */
    public function reply(User $user, Lead $lead): bool
    {
        return $lead->branch_id !== null && $this->view($user, $lead);
    }
}
