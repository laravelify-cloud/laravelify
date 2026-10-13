@php
    $ms = fn (?int $value): string => $value === null ? '—' : ($value >= 1000 ? rtrim(rtrim(number_format($value / 1000, 2), '0'), '.').'s' : number_format($value).'ms');
    $num = fn (int|float|null $value): string => match (true) {
        $value === null => '—',
        $value >= 1000000 => round($value / 1000000, 1).'M',
        $value >= 1000 => round($value / 1000, 1).'k',
        default => (string) $value,
    };
@endphp

<div class="flex flex-col gap-6" wire:poll.60s.visible>
    <x-page-header title="Monitoring" summary="Uptime, traffic, errors, speed, and attacks for every website over the last 24 hours. Open a site for charts, requests, issues, and IP blocking.">
        <x-slot:actions>
            <span class="mon-live" title="Refreshes every minute">Live</span>
        </x-slot:actions>
    </x-page-header>

    <div class="mon-grid-4">
        <div class="mon-card">
            <p class="mon-label">Availability</p>
            <p class="mon-value">{{ $totals['checked'] > 0 ? $totals['up'].' / '.$totals['checked'] : '—' }}</p>
            <p class="mon-sub">{{ $totals['checked'] === 0 ? 'No uptime checks yet' : ($totals['up'] === $totals['checked'] ? 'All systems operational' : ($totals['checked'] - $totals['up']).' down') }}</p>
        </div>
        <div class="mon-card">
            <p class="mon-label">Requests · 24h</p>
            <p class="mon-value">{{ $num($totals['requests']) }}</p>
            <p class="mon-sub">{{ $num($totals['server_errors']) }} server errors</p>
        </div>
        <div class="mon-card">
            <p class="mon-label">Open issues</p>
            <p class="mon-value">{{ $totals['issues'] }}</p>
            <p class="mon-sub">From Laravel logs</p>
        </div>
        <div class="mon-card">
            <p class="mon-label">Threats · 24h</p>
            <p class="mon-value" @style(['color: var(--mon-5xx)' => $totals['threats'] > 0])>{{ $num($totals['threats']) }}</p>
            <p class="mon-sub">High and critical requests</p>
        </div>
    </div>

    @if ($websites->isEmpty())
        <div class="mon-card mon-empty">
            <x-menu-icon name="chart" class="size-5" />
            No websites yet. Create one from Groups to start monitoring it.
        </div>
    @else
        <div class="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
            @foreach ($websites as $website)
                @php
                    $stats = $overview[$website->id];
                    $state = $stats['up'] === null ? 'none' : ($stats['up'] ? 'up' : 'down');
                @endphp
                <a class="mon-card flex flex-col gap-4 transition-colors hover:border-[var(--color-accent)]" href="{{ route('websites.show', ['website' => $website, 'tab' => 'monitoring']) }}" wire:navigate wire:key="monitor-site-{{ $website->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-[15px] font-semibold">{{ $website->name }}</p>
                            <p class="truncate text-[12px] text-muted">{{ $website->domain ?: $website->group?->name }}</p>
                        </div>
                        <span class="status-badge" style="--status-color: {{ ['up' => 'var(--mon-up)', 'down' => 'var(--mon-5xx)', 'none' => 'var(--color-muted)'][$state] }}">{{ ['up' => 'Up', 'down' => 'Down', 'none' => 'Not checked'][$state] }}</span>
                    </div>

                    <x-monitor.spark :values="$stats['hours']" />

                    <div class="grid grid-cols-4 gap-2">
                        <div>
                            <p class="mon-label text-[10px]">Uptime</p>
                            <p class="mt-1 text-[14px] font-semibold tabular-nums">{{ $stats['uptime'] !== null ? $stats['uptime'].'%' : '—' }}</p>
                        </div>
                        <div>
                            <p class="mon-label text-[10px]">Requests</p>
                            <p class="mt-1 text-[14px] font-semibold tabular-nums">{{ $num($stats['requests']) }}</p>
                        </div>
                        <div>
                            <p class="mon-label text-[10px]">P95</p>
                            <p class="mt-1 text-[14px] font-semibold tabular-nums" @style(['color: var(--mon-p95)' => $stats['p95'] !== null && $stats['p95'] > $website->monitor_slow_ms])>{{ $ms($stats['p95']) }}</p>
                        </div>
                        <div>
                            <p class="mon-label text-[10px]">5xx</p>
                            <p class="mt-1 text-[14px] font-semibold tabular-nums" @style(['color: var(--mon-5xx)' => $stats['server_errors'] > 0])>{{ $num($stats['server_errors']) }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-t border-line pt-3 text-[12px] text-muted">
                        <span class="inline-flex items-center gap-1.5"><x-menu-icon name="bug" class="size-3.5" /> {{ (int) ($openIssues[$website->id] ?? 0) }} open {{ (int) ($openIssues[$website->id] ?? 0) === 1 ? 'issue' : 'issues' }}</span>
                        <span class="inline-flex items-center gap-1.5"><x-menu-icon name="shield" class="size-3.5" /> {{ $num($stats['threats']) }} threats</span>
                        @if ($stats['checked_at'])
                            <span class="ml-auto">Checked <span title="@datetime($stats['checked_at'])">@ago($stats['checked_at'], true)</span></span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
