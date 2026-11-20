@php
    $items = [
        ['route' => 'inbox', 'match' => 'inbox*', 'label' => 'Inbox', 'admin' => false,
         'icon' => 'M2.25 13.5h3.86a2.25 2.25 0 0 1 2.012 1.244l.256.512a2.25 2.25 0 0 0 2.013 1.244h3.218a2.25 2.25 0 0 0 2.013-1.244l.256-.512a2.25 2.25 0 0 1 2.013-1.244h3.859M2.25 13.5V18A2.25 2.25 0 0 0 4.5 20.25h15A2.25 2.25 0 0 0 21.75 18v-4.5M2.25 13.5l2.4-7.2A2.25 2.25 0 0 1 6.784 4.75h10.432a2.25 2.25 0 0 1 2.134 1.55l2.4 7.2'],
        ['route' => 'branches.index', 'match' => 'branches.*', 'label' => 'Branches', 'admin' => true,
         'icon' => 'M2.25 21h19.5M3.75 3v18m16.5-18v18M5.25 3h13.5M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21'],
        ['route' => 'users.index', 'match' => 'users.*', 'label' => 'Users', 'admin' => true,
         'icon' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128H2.25v-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z'],
        ['route' => 'reports', 'match' => 'reports*', 'label' => 'Reports', 'admin' => false,
         'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z'],
    ];
@endphp
<nav class="space-y-1">
    @foreach ($items as $item)
        @continue($item['admin'] && ! auth()->user()->isAdmin())
        @php($active = request()->routeIs($item['match']))
        <a href="{{ route($item['route']) }}"
           @class(['flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                   'bg-orange-50 text-orange-700' => $active,
                   'text-slate-600 hover:bg-slate-100' => ! $active])>
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
            {{ $item['label'] }}
        </a>
    @endforeach
</nav>
