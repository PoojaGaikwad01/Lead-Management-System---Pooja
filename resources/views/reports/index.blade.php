@php
    use App\Enums\LeadSource;
    use App\Support\Format;
    $isAdmin = auth()->user()->isAdmin();
    $cards = [
        ['Leads received', number_format($totals['total']), null],
        ['Pending reply', number_format($totals['pending']), 'Assigned, not replied yet'],
        ['Replied', number_format($totals['replied']), $totals['total'] ? round($totals['replied'] / $totals['total'] * 100).'% of leads' : null],
        ['Avg time to reply', Format::duration($totals['avg_reply']), 'From assignment to first reply'],
    ];
    if ($isAdmin) {
        $cards[] = ['Needs review', number_format($totals['needs_review']), 'Agency leads to fix'];
    }
@endphp
<x-layouts.app title="Reports">
    <x-slot:actions>
        <a href="{{ route('reports.export', request()->query()) }}" class="btn btn-secondary">⬇ Export CSV</a>
    </x-slot:actions>

    <form method="GET" class="card mb-4 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end">
        <label class="block"><span class="label">From</span><input type="date" name="from" value="{{ $from->toDateString() }}" class="input"></label>
        <label class="block"><span class="label">To</span><input type="date" name="to" value="{{ $to->toDateString() }}" class="input"></label>
        <label class="block"><span class="label">Source</span>
            <select name="source" class="input">
                <option value="">All sources</option>
                @foreach (LeadSource::cases() as $s)
                    <option value="{{ $s->value }}" @selected(request('source') === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select>
        </label>
        @if ($isAdmin)
            <label class="block"><span class="label">Branch</span>
                <select name="branch_id" class="input">
                    <option value="">All branches</option>
                    <option value="none" @selected(request('branch_id') === 'none')>Unassigned</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="flex gap-2">
            <button class="btn btn-primary">Apply</button>
            <a href="{{ route('reports') }}" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    @unless ($isAdmin)
        <p class="mb-4 text-sm text-slate-500">Showing leads for {{ auth()->user()->branch?->name ?? 'your branch' }} only.</p>
    @endunless

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ($cards as [$label, $value, $hint])
            <div class="card p-4">
                <div class="text-xs font-medium text-slate-500 uppercase">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                @if ($hint)<div class="mt-0.5 text-xs text-slate-400">{{ $hint }}</div>@endif
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        @foreach (array_filter(['By source' => $bySource, 'By branch' => $isAdmin ? $byBranch : null]) as $heading => $rows)
            <section class="card overflow-x-auto">
                <h2 class="px-4 pt-4 pb-2 font-semibold">{{ $heading }}</h2>
                <table class="table">
                    <thead><tr><th>{{ $heading === 'By source' ? 'Source' : 'Branch' }}</th><th class="text-right">Leads</th><th class="text-right">Pending</th><th class="text-right">Replied</th><th class="text-right">Avg reply</th><th class="text-right">Reminders</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $name => $r)
                            <tr @class(['text-slate-400' => ! $r['total']])>
                                <td class="font-medium">{{ $name }}</td>
                                <td class="text-right">{{ $r['total'] }}</td>
                                <td class="text-right">{{ $r['pending'] }}</td>
                                <td class="text-right">{{ $r['replied'] }}</td>
                                <td class="text-right whitespace-nowrap">{{ Format::duration($r['avg_reply']) }}</td>
                                <td class="text-right">{{ $r['reminders'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        @endforeach

        <section class="card overflow-x-auto">
            <h2 class="px-4 pt-4 pb-2 font-semibold">By status</h2>
            <table class="table">
                <thead><tr><th>Status</th><th class="text-right">Leads</th></tr></thead>
                <tbody>
                    @foreach ($byStatus as $name => $count)
                        <tr @class(['text-slate-400' => ! $count])><td class="font-medium">{{ $name }}</td><td class="text-right">{{ $count }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="card overflow-x-auto">
            <h2 class="px-4 pt-4 pb-2 font-semibold">By day</h2>
            <div class="max-h-96 overflow-y-auto">
                <table class="table">
                    <thead><tr><th>Date</th><th class="text-right">Leads</th></tr></thead>
                    <tbody>
                        @forelse ($byDay as $date => $count)
                            <tr><td>{{ \Illuminate\Support\Carbon::parse($date)->format('D, d M Y') }}</td><td class="text-right">{{ $count }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="py-6 text-center text-slate-500">No leads in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
