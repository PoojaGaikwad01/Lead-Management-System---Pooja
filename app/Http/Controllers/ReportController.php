<?php

namespace App\Http\Controllers;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Branch;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Counts by source / branch / status / day for a date range (lead received date), average time to reply, CSV export.
 * "Time to reply" = replied_at − assigned_at (first reply after the latest assignment).
 * Branch Managers only ever see their own branch.
 */
class ReportController extends Controller
{
    public function index(Request $request)
    {
        [$query, $from, $to] = $this->query($request);
        $leads = $query->withCount('reminders')->get(['id', 'source', 'status', 'branch_id', 'assigned_at', 'replied_at', 'created_at']);
        $isAdmin = $request->user()->isAdmin();

        $branches = $isAdmin ? Branch::orderBy('name')->get(['id', 'name', 'is_active']) : collect();

        return view('reports.index', [
            'from' => $from,
            'to' => $to,
            'branches' => $branches,
            'totals' => $this->summarize($leads) + ['needs_review' => $leads->where('status', LeadStatus::NeedsReview)->count()],
            'bySource' => collect(LeadSource::cases())->mapWithKeys(fn ($s) => [$s->label() => $this->summarize($leads->where('source', $s))]),
            'byBranch' => $isAdmin
                ? $branches->mapWithKeys(fn ($b) => [$b->name => $this->summarize($leads->where('branch_id', $b->id))])
                    ->put('Unassigned', $this->summarize($leads->whereNull('branch_id')))
                : collect(),
            'byStatus' => collect(LeadStatus::cases())->mapWithKeys(fn ($s) => [$s->label() => $leads->where('status', $s)->count()]),
            'byDay' => $leads->groupBy(fn ($l) => $l->created_at->toDateString())->map->count()->sortKeysDesc(),
        ]);
    }

    public function export(Request $request)
    {
        [$query, $from, $to] = $this->query($request);
        $query->with(['branch:id,name', 'assignedBy:id,name'])->withCount('reminders');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names/₹ correctly
            fputcsv($out, ['ID', 'Received', 'Source', 'Status', 'Name', 'Phone', 'Email', 'City', 'Branch', 'Agency',
                'Campaign', 'Ad', 'Form', 'Assigned at', 'Assigned by', 'Replied at', 'Minutes to reply', 'Reminders'], ',', '"', '');

            $query->chunkById(500, function ($leads) use ($out) {
                foreach ($leads as $l) {
                    fputcsv($out, array_map($this->csvSafe(...), [
                        $l->id, $l->created_at->format('Y-m-d H:i'), $l->source->label(), $l->status->label(),
                        $l->name, $l->phone, $l->email, $l->city, $l->branch?->name, $l->agency_name,
                        $l->campaign_name, $l->ad_name, $l->form_name,
                        $l->assigned_at?->format('Y-m-d H:i'), $l->assignedBy?->name, $l->replied_at?->format('Y-m-d H:i'),
                        $this->minutesToReply($l), $l->reminders_count,
                    ]), ',', '"', '');
                }
            });
            fclose($out);
        }, 'leads-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: Builder, 1: Carbon, 2: Carbon} */
    private function query(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable'],
            'source' => ['nullable', Rule::enum(LeadSource::class)],
        ]);
        $from = Carbon::parse($data['from'] ?? now()->subDays(29))->startOfDay();
        $to = Carbon::parse($data['to'] ?? now())->endOfDay();
        $branch = $request->user()->isAdmin() ? ($data['branch_id'] ?? null) : null;

        $query = Lead::visibleTo($request->user())
            ->whereBetween('leads.created_at', [$from, $to])
            ->when($data['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->when($branch, fn ($q, $v) => $v === 'none' ? $q->whereNull('branch_id') : $q->where('branch_id', $v));

        return [$query, $from, $to];
    }

    /** @return array{total: int, pending: int, replied: int, avg_reply: ?float, reminders: int} */
    private function summarize(Collection $leads): array
    {
        return [
            'total' => $leads->count(),
            'pending' => $leads->where('status', LeadStatus::Assigned)->count(),
            'replied' => $leads->whereNotNull('replied_at')->count(),
            'avg_reply' => $leads->map(fn ($l) => $this->minutesToReply($l))->filter(fn ($m) => $m !== null)->avg(),
            'reminders' => (int) $leads->sum('reminders_count'),
        ];
    }

    private function minutesToReply(Lead $lead): ?int
    {
        return $lead->assigned_at && $lead->replied_at ? (int) max(0, $lead->assigned_at->diffInMinutes($lead->replied_at)) : null;
    }

    /** Stop spreadsheet formula injection (also keeps "+91…" phones as text in Excel). */
    private function csvSafe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
