<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InboxController extends Controller
{
    public function index(Request $request)
    {
        return view('inbox.index', $this->listData($request) + ['lead' => null]);
    }

    public function show(Request $request, Lead $lead)
    {
        Gate::authorize('view', $lead);

        $lead->load(['messages.sender:id,name', 'branch:id,name,city', 'assignedBy:id,name', 'reminders']);

        return view('inbox.index', $this->listData($request) + ['lead' => $lead, 'window' => $lead->replyWindow()]);
    }

    /**
     * Polled by the inbox every few seconds: leads created after ?since={id} that this user can see
     * and that match the current filters. The page then reloads its list pane and highlights them.
     */
    public function updates(Request $request): JsonResponse
    {
        $since = max(0, (int) $request->query('since'));
        $visible = fn () => Lead::visibleTo($request->user());

        $new = $visible()->filter($request->only('q', 'source', 'status', 'branch_id'))
            ->where('id', '>', $since)
            ->orderBy('id')->limit(20)
            ->get(['id', 'name', 'phone', 'city', 'source']);

        return response()->json([
            'latest_id' => max($since, (int) $visible()->max('id')),
            'new' => $new->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'name' => $lead->display_name,
                'source' => $lead->source->label(),
                'city' => $lead->city,
            ]),
        ]);
    }

    /** Left pane: filtered, branch-scoped lead list + per-status counts. */
    private function listData(Request $request): array
    {
        $user = $request->user();
        $filters = $request->only('q', 'source', 'status', 'branch_id');

        $leads = Lead::visibleTo($user)
            ->filter($filters)
            ->with(['latestMessage', 'branch:id,name'])
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $statusCounts = Lead::visibleTo($user)
            ->filter(array_diff_key($filters, ['status' => 1]))
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $branches = $user->isAdmin() ? Branch::orderBy('name')->get(['id', 'name', 'is_active']) : collect();

        // Highest lead id this user can see: live polling starts from here.
        $latestId = (int) Lead::visibleTo($user)->max('id');

        return compact('leads', 'statusCounts', 'branches', 'latestId');
    }
}
