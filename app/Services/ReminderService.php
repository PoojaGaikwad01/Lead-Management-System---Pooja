<?php

namespace App\Services;

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\LeadReminder;
use Illuminate\Support\Facades\Notification;

class ReminderService
{
    /** Remind the active managers of the lead's branch. Only for pending leads (assigned, no reply). Returns recipients count. */
    public function remind(Lead $lead, bool $automatic = false): int
    {
        if ($lead->status !== LeadStatus::Assigned || ! $lead->branch_id) {
            return 0;
        }

        $managers = User::where('role', Role::BranchManager)
            ->where('branch_id', $lead->branch_id)
            ->where('is_active', true)
            ->get();

        foreach ($managers as $manager) {
            Reminder::create(['lead_id' => $lead->id, 'sent_to_user_id' => $manager->id, 'sent_at' => now()]);
        }
        Notification::send($managers, new LeadReminder($lead, $automatic));

        return $managers->count();
    }
}
