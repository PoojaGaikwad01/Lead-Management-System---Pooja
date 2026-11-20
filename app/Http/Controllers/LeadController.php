<?php

namespace App\Http\Controllers;

use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use App\Services\Meta\MetaClient;
use App\Services\ReminderService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /** Admin corrects lead details. Saving a needs-review lead with a valid phone clears the flag. */
    public function update(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', function ($attribute, $value, $fail) {
                if (filled($value) && ! Phone::normalize($value)) {
                    $fail('Enter a valid mobile number (10 digits, or with +country code).');
                }
            }],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'agency_name' => ['nullable', 'string', 'max:255'],
        ]);

        $lead->fill($data);

        $reviewed = $lead->status === LeadStatus::NeedsReview && $lead->phone;
        if ($reviewed) {
            $lead->status = $lead->branch_id ? LeadStatus::Assigned : LeadStatus::New;
        }
        $lead->save();

        return back()->with('status', match (true) {
            (bool) $reviewed => 'Saved and marked as reviewed.',
            $lead->status === LeadStatus::NeedsReview => 'Saved. Still needs review — add a phone number.',
            default => 'Lead saved.',
        });
    }

    /** Admin: assign or reassign one lead. */
    public function assign(Request $request, Lead $lead)
    {
        $branch = $this->activeBranch($request);

        if ($lead->status === LeadStatus::NeedsReview) {
            return back()->withErrors(['branch_id' => 'Review and fix this lead before assigning it.']);
        }

        $lead->assignTo($branch, $request->user());

        return back()->with('status', "Assigned to {$branch->name}.");
    }

    /** Admin: assign many leads at once. Needs-review leads are skipped. */
    public function bulkAssign(Request $request)
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:100'], 'ids.*' => ['integer']])['ids'];
        $branch = $this->activeBranch($request);

        $leads = Lead::whereIn('id', $ids)->get();
        [$review, $assignable] = $leads->partition(fn (Lead $l) => $l->status === LeadStatus::NeedsReview);
        $assignable->each(fn (Lead $l) => $l->assignTo($branch, $request->user()));

        return back()->with('status', "{$assignable->count()} lead(s) assigned to {$branch->name}."
            .($review->isNotEmpty() ? " {$review->count()} skipped (need review)." : ''));
    }

    /** Free-text reply on the customer's channel — only inside the 24h window. Mock mode stores without calling Meta. */
    public function reply(Request $request, Lead $lead, MetaClient $meta)
    {
        Gate::authorize('reply', $lead);
        $body = $request->validate(['body' => ['required', 'string', 'max:2000']])['body'];

        $window = $lead->replyWindow();
        if (! $window['open']) {
            return back()->withInput($this->draft($body))->withErrors(['body' => '24h window closed — free-text replies are disabled. Call or WhatsApp the customer, then use "Mark reply sent".']);
        }

        try {
            $externalId = $meta->sendText($window['channel'], $window['recipient'], $body);
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput($this->draft($body))->withErrors(['body' => 'Could not send: '.Str::limit($e->getMessage(), 160)]);
        }

        $lead->messages()->create([
            'direction' => 'out',
            'channel' => $window['channel'],
            'body' => $body,
            'external_message_id' => $externalId,
            'sent_by_user_id' => $request->user()->id,
        ]);
        $lead->markReplied();

        return back()->with('status', 'Reply sent.');
    }

    /** Replied outside the app (call / personal WhatsApp) — logged as a note in the thread. */
    public function markReplied(Request $request, Lead $lead)
    {
        Gate::authorize('reply', $lead);

        $lead->messages()->create(['direction' => 'out', 'channel' => 'note', 'body' => 'Marked reply sent', 'sent_by_user_id' => $request->user()->id]);
        $lead->markReplied();

        return back()->with('status', 'Marked as reply sent.');
    }

    public function close(Request $request, Lead $lead)
    {
        Gate::authorize('reply', $lead);

        $lead->messages()->create(['direction' => 'out', 'channel' => 'note', 'body' => 'Closed lead', 'sent_by_user_id' => $request->user()->id]);
        $lead->update(['status' => LeadStatus::Closed]);

        return back()->with('status', 'Lead closed.');
    }

    /** Admin: nudge the branch managers about a pending lead (assigned, not replied). */
    public function remind(Lead $lead, ReminderService $reminders)
    {
        if ($lead->status !== LeadStatus::Assigned) {
            return back()->withErrors(['lead' => 'Reminders are only for assigned leads that are still waiting for a reply.']);
        }

        $sent = $reminders->remind($lead);

        return $sent
            ? back()->with('status', "Reminder sent to {$sent} manager(s).")
            : back()->withErrors(['lead' => 'This branch has no active manager to remind.']);
    }

    /** Keep the typed reply after an error only if it's short — cookie sessions are capped at ~4 KB. */
    private function draft(string $body): array
    {
        return mb_strlen($body) <= 500 ? ['body' => $body] : [];
    }

    private function activeBranch(Request $request): Branch
    {
        $id = $request->validate(['branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)]])['branch_id'];

        return Branch::findOrFail($id);
    }
}
