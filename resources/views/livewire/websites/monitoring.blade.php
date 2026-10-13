@php
    use App\Services\SiteMetrics;
    use App\Services\ThreatDetector;

    $ms = fn (?int $value): string => $value === null ? '—' : ($value >= 1000 ? rtrim(rtrim(number_format($value / 1000, 2), '0'), '.').'s' : number_format($value).'ms');
    $num = fn (int|float|null $value): string => match (true) {
        $value === null => '—',
        $value >= 1000000 => round($value / 1000000, 1).'M',
        $value >= 1000 => round($value / 1000, 1).'k',
        default => (string) $value,
    };
    $codeClass = fn (int $status): string => 'is-'.max(1, min(5, intdiv($status, 100))).'xx';
@endphp

<div class="flex flex-col gap-5" @if (! $issueId) wire:poll.60s.visible @endif>
    {{-- Toolbar: views on the left, time range on the right --}}
    <div class="mon-toolbar">
        <div class="segmented" role="tablist" aria-label="Monitoring views">
            @foreach (\App\Livewire\Websites\Monitoring::VIEWS as $key => $label)
                <button type="button" wire:click="setView('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" class="!flex items-center gap-1.5 text-[12.5px] font-medium">
                    {{ $label }}
                    @if ($key === 'issues' && $openIssues > 0)
                        <span class="pill h-[17px] px-1.5 text-[10.5px]">{{ $openIssues }}</span>
                    @endif
                </button>
            @endforeach
        </div>
        <div class="flex items-center gap-3">
            <span class="text-[12px] text-muted max-sm:hidden" title="Charts refresh every minute, when new data is collected">Charts update every minute</span>
            <div class="segmented" role="group" aria-label="Time range">
                @foreach (SiteMetrics::RANGES as $key => [$label])
                    <button type="button" wire:click="setRange('{{ $key }}')" aria-pressed="{{ $range === $key ? 'true' : 'false' }}" class="font-mono text-[11.5px] font-semibold">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    @unless ($tracking)
        <div class="mon-card flex flex-wrap items-center gap-4">
            <div class="stat-icon"><x-menu-icon name="chart" /></div>
            <div class="min-w-0 flex-1">
                <p class="font-medium">Request tracking is not on for this site yet</p>
                <p class="mt-0.5 text-[12.5px] text-muted">nginx needs one include line in this site's config to log requests for these charts. Turning it on reloads nginx only; workers keep running. Uptime and Laravel errors are tracked either way.</p>
            </div>
            <button class="btn btn-primary" type="button" wire:click="enableTracking" wire:loading.attr="disabled" wire:target="enableTracking">
                <x-menu-icon name="play" /> Turn on tracking
            </button>
        </div>
    @endunless

    {{-- Live: polls the request log every 2 seconds on its own; Livewire never re-renders it. --}}
    @if ($tracking && in_array($view, ['overview', 'requests'], true))
        <div class="mon-card" wire:ignore wire:key="live-{{ $website->id }}-{{ $view }}" x-data="monitorLive(@js(route('monitoring.website.live', $website)), {{ $view === 'requests' ? 25 : 10 }})">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2.5">
                    <span class="mon-live" :class="(paused || failed) && 'is-paused'" x-text="paused ? 'Paused' : (failed ? 'Reconnecting…' : 'Live')"></span>
                    <span class="mon-label">Last 60 seconds</span>
                </div>
                <button class="btn" type="button" @click="paused = !paused; if (!paused) tick()">
                    <x-menu-icon name="play" x-show="paused" />
                    <x-menu-icon name="pause" x-show="!paused" />
                    <span x-text="paused ? 'Resume' : 'Pause'"></span>
                </button>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-4 md:grid-cols-5">
                <div><p class="mon-label">Requests</p><p class="mon-value tabular-nums" x-text="monitorFormat.number(stats.total)">0</p></div>
                <div><p class="mon-label">Per second</p><p class="mon-value mon-value-sm tabular-nums" x-text="stats.rps">0</p></div>
                <div><p class="mon-label"><span class="mon-dot is-4xx mr-1.5"></span>4xx</p><p class="mon-value mon-value-sm tabular-nums" x-text="stats.client">0</p></div>
                <div><p class="mon-label"><span class="mon-dot is-5xx mr-1.5"></span>5xx</p><p class="mon-value mon-value-sm tabular-nums" :style="stats.server && 'color: var(--mon-5xx)'" x-text="stats.server">0</p></div>
                <div><p class="mon-label">Avg response</p><p class="mon-value mon-value-sm tabular-nums" :style="stats.avg > slow && 'color: var(--mon-p95)'" x-text="monitorFormat.ms(stats.avg)">—</p></div>
            </div>

            <div class="mon-live-bars mt-4" aria-hidden="true">
                <template x-for="bar in bars" :key="bar.t">
                    <span :style="'height:' + bar.h + '%'" :class="bar.s ? 'is-5xx' : (bar.c ? 'is-4xx' : '')" :title="time(bar.t) + ': ' + bar.n + ' requests'"></span>
                </template>
            </div>

            <div class="mon-feed mt-4">
                <template x-for="row in feed" :key="row.id">
                    <div class="mon-feed-row">
                        <span class="w-[64px] shrink-0 whitespace-nowrap font-mono text-[11.5px] text-muted" x-text="time(row.t)"></span>
                        <span class="mon-code shrink-0" x-text="row.m"></span>
                        <span class="mon-path min-w-0 flex-1" :title="row.u" x-text="row.u"></span>
                        <template x-if="row.r > 0"><span class="mon-risk shrink-0" :class="riskClass(row.r)" x-text="row.th"></span></template>
                        <span class="mon-code shrink-0" :class="codeClass(row.s)" x-text="row.s"></span>
                        <span class="w-[58px] shrink-0 text-right font-mono text-[12px] tabular-nums" :style="row.d >= slow && 'color: var(--mon-p95)'" x-text="monitorFormat.ms(row.d)"></span>
                        <span class="hidden w-[150px] shrink-0 truncate font-mono text-[11.5px] text-muted md:block" x-text="row.ip + (row.c ? ' · ' + row.c : '')"></span>
                    </div>
                </template>
                <p class="py-4 text-center text-[12.5px] text-muted" x-show="feed.length === 0">Waiting for requests… open the site in another tab to see them appear here.</p>
            </div>
        </div>
    @endif

    <div wire:loading.class="opacity-60" wire:target="setView, setRange, setFilter, search" class="flex flex-col gap-5 transition-opacity">
        {{-- Overview --}}
        @if ($view === 'overview')
            @php
                $latest = $uptime['latest'];
                $state = $latest === null ? 'none' : ($latest->ok ? 'up' : 'down');
                $summary = $traffic['summary'];
            @endphp

            <div class="mon-grid-2 mon-grid-hero">
                <div class="mon-card">
                    <div class="flex items-center justify-between gap-3">
                        <p class="mon-label">Availability</p>
                        <span class="status-badge" style="--status-color: {{ ['up' => 'var(--mon-up)', 'down' => 'var(--mon-5xx)', 'none' => 'var(--color-muted)'][$state] }}">{{ ['up' => 'Up', 'down' => 'Down', 'none' => 'No checks yet'][$state] }}</span>
                    </div>
                    <p class="mon-hero-title">{{ ['up' => 'All systems operational', 'down' => 'Website is not responding', 'none' => 'Waiting for the first check'][$state] }}</p>
                    <p class="mon-sub">
                        @if ($latest)
                            Last check <span title="@datetime($latest->checked_at)">@ago($latest->checked_at)</span> · {{ $latest->status ? 'HTTP '.$latest->status : \Illuminate\Support\Str::limit($latest->error, 60) }} · {{ $ms($latest->duration_ms) }}
                        @else
                            Checked every minute once the site has a successful deploy and an address.
                        @endif
                    </p>
                    <x-monitor.strip :buckets="$uptime['buckets']" />
                    <div class="mt-2 flex justify-between text-[12px] text-muted">
                        <span>{{ $uptime['percent'] !== null ? $uptime['percent'].'% uptime' : 'No data' }}</span>
                        <span>{{ number_format($uptime['checks']) }} checks</span>
                    </div>
                </div>

                <div class="mon-card">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <p class="mon-label">Performance</p>
                            <p class="mon-value">{{ $ms($summary['p95']) }}</p>
                            <p class="mon-sub">p95 response · limit {{ $ms($website->monitor_slow_ms) }}</p>
                            <div class="mon-meter mt-3"><span style="width: {{ $summary['p95'] ? min(100, round($summary['p95'] / max(1, $website->monitor_slow_ms) * 100)) : 0 }}%; {{ $summary['p95'] > $website->monitor_slow_ms ? 'background: var(--mon-5xx)' : '' }}"></span></div>
                        </div>
                        <div>
                            <p class="mon-label">Error rate</p>
                            <p class="mon-value">{{ $summary['error_rate'] !== null ? $summary['error_rate'].'%' : '—' }}</p>
                            <p class="mon-sub">{{ number_format($summary['server_errors']) }} server errors (5xx)</p>
                        </div>
                        <button class="text-left" type="button" wire:click="setView('issues')">
                            <p class="mon-label">Open issues</p>
                            <p class="mon-value mon-value-sm">{{ $openIssues }}</p>
                            <p class="mon-sub">{{ $website->isLaravel() ? 'From the Laravel log' : 'Laravel sites only' }}</p>
                        </button>
                        <button class="text-left" type="button" wire:click="setView('security')">
                            <p class="mon-label">Threats</p>
                            <p class="mon-value mon-value-sm" @style(['color: var(--mon-5xx)' => $threats['levels']['critical'] > 0])>{{ $num($threats['total']) }}</p>
                            <p class="mon-sub">{{ $threats['levels']['critical'] }} critical · {{ $threats['levels']['high'] }} high</p>
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2.5">
                <div class="stat-icon size-8"><x-menu-icon name="pulse" /></div>
                <h3 class="mon-label text-[12px] text-ink">Activity</h3>
                <button class="btn ml-auto" type="button" wire:click="setView('requests')">Requests <x-menu-icon name="arrow-up-right" /></button>
            </div>

            <div class="mon-grid-2">
                <div class="mon-card">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="mon-label">Requests</p>
                            <p class="mon-value">{{ $num($summary['requests']) }}</p>
                        </div>
                        <div class="mon-legend">
                            <button type="button" class="mon-legend-item" wire:click="showRequests('ok')"><p class="mon-label"><span class="mon-dot"></span>1/2/3xx</p><p>{{ $num($summary['ok']) }}</p></button>
                            <button type="button" class="mon-legend-item" wire:click="showRequests('4xx')"><p class="mon-label"><span class="mon-dot is-4xx"></span>4xx</p><p>{{ $num($summary['client_errors']) }}</p></button>
                            <button type="button" class="mon-legend-item" wire:click="showRequests('5xx')"><p class="mon-label"><span class="mon-dot is-5xx"></span>5xx</p><p>{{ $num($summary['server_errors']) }}</p></button>
                        </div>
                    </div>
                    <x-monitor.bars :buckets="$traffic['buckets']" />
                </div>

                <div class="mon-card">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p class="mon-label">Duration</p>
                            <p class="mon-value">{{ $summary['requests'] > 0 ? $ms($summary['p50']).' – '.$ms($summary['max']) : '—' }}</p>
                        </div>
                        <div class="mon-legend">
                            <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot is-avg"></span>Avg</p><p>{{ $ms($summary['avg']) }}</p></div>
                            <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot is-p95"></span>P95</p><p>{{ $ms($summary['p95']) }}</p></div>
                        </div>
                    </div>
                    <x-monitor.lines :buckets="$traffic['buckets']" />
                </div>
            </div>

            <div class="mon-grid-2 mon-grid-side items-start">
                <x-layer-card title="Top routes" helper="Busiest paths in this range. IDs are grouped, like /orders/{id}." flush>
                    @include('livewire.websites.partials.monitoring-routes', ['routes' => $routes])
                </x-layer-card>

                <div class="flex min-w-0 flex-col gap-5">
                    <x-layer-card title="Open issues" helper="New Laravel errors, grouped." flush>
                        <x-slot:actions>
                            <button class="btn" type="button" wire:click="setView('issues')">All issues</button>
                        </x-slot:actions>
                        @forelse ($recentIssues as $recent)
                            <button class="mon-issue" type="button" wire:click="openIssue({{ $recent->id }})" wire:key="recent-issue-{{ $recent->id }}">
                                <x-menu-icon name="bug" class="mt-0.5 shrink-0 text-[var(--mon-5xx)]" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13px] font-medium">{{ $recent->title() }}</span>
                                    <span class="block truncate text-[12px] text-muted">{{ $recent->message }}</span>
                                </span>
                                <span class="shrink-0 text-[12px] text-muted">{{ $recent->occurrences }}×</span>
                            </button>
                        @empty
                            <div class="mon-empty"><x-menu-icon name="check" class="size-5 text-[var(--mon-up)]" />No open issues.</div>
                        @endforelse
                    </x-layer-card>

                    <x-layer-card title="Countries" helper="From Cloudflare, for sites behind a tunnel." flush>
                        @php $countryTotal = max(1, (int) $countries->sum('hits')); @endphp
                        <div class="flex flex-col gap-3 p-4">
                            @forelse ($countries as $country)
                                <div wire:key="country-{{ $country->country }}">
                                    <div class="mb-1 flex justify-between text-[12.5px]"><span class="font-medium">{{ $country->country }}</span><span class="text-muted">{{ $num((int) $country->hits) }}</span></div>
                                    <div class="mon-meter"><span style="width: {{ round($country->hits / $countryTotal * 100) }}%"></span></div>
                                </div>
                            @empty
                                <p class="text-[12.5px] text-muted">No country data yet.</p>
                            @endforelse
                        </div>
                    </x-layer-card>
                </div>
            </div>

            <form wire:submit="saveSettings">
                <x-layer-card title="Monitoring settings" helper="Alert limits for Telegram, and how long request details are kept.">
                    <x-slot:actions>
                        <button class="btn btn-primary" type="submit">Save</button>
                    </x-slot:actions>
                    <div class="grid gap-4 md:grid-cols-3">
                        <label class="field">
                            <span class="label">Keep request details</span>
                            <select class="select" wire:model="retention_days">
                                @foreach ([1, 3, 7, 14, 30, 60, 90] as $days)
                                    <option value="{{ $days }}">{{ $days }} {{ $days === 1 ? 'day' : 'days' }}</option>
                                @endforeach
                            </select>
                            <span class="text-[12px] text-muted">Charts and uptime keep at least 30 days.</span>
                            @error('retention_days') <span class="error text-[12px]">{{ $message }}</span> @enderror
                        </label>
                        <label class="field">
                            <span class="label">Slow when p95 is above (ms)</span>
                            <input class="input" type="number" min="100" step="100" wire:model="slow_ms">
                            <span class="text-[12px] text-muted">Alerts with 20+ requests in 5 minutes.</span>
                            @error('slow_ms') <span class="error text-[12px]">{{ $message }}</span> @enderror
                        </label>
                        <label class="field">
                            <span class="label">Error spike at (5xx in 5 minutes)</span>
                            <input class="input" type="number" min="1" wire:model="error_threshold">
                            <span class="text-[12px] text-muted">One alert, then quiet for 30 minutes.</span>
                            @error('error_threshold') <span class="error text-[12px]">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </x-layer-card>
            </form>
        @endif

        {{-- Requests --}}
        @if ($view === 'requests')
            <div class="mon-card">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="mon-label">Requests</p>
                        <p class="mon-value">{{ $num($traffic['summary']['requests']) }}</p>
                    </div>
                    <div class="mon-legend">
                        <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot"></span>1/2/3xx</p><p>{{ $num($traffic['summary']['ok']) }}</p></div>
                        <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot is-4xx"></span>4xx</p><p>{{ $num($traffic['summary']['client_errors']) }}</p></div>
                        <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot is-5xx"></span>5xx</p><p>{{ $num($traffic['summary']['server_errors']) }}</p></div>
                        <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot is-p95"></span>P95</p><p>{{ $ms($traffic['summary']['p95']) }}</p></div>
                    </div>
                </div>
                <x-monitor.bars :buckets="$traffic['buckets']" :height="120" />
            </div>

            <x-layer-card title="Requests" helper="Newest first. Static files (js, css, images) are left out unless they failed." flush>
                <x-slot:actions>
                    <div class="segmented" role="group" aria-label="Filter requests">
                        @foreach (\App\Livewire\Websites\Monitoring::FILTERS as $key => $label)
                            <button type="button" wire:click="setFilter('{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" class="text-[12px]">{{ $label }}</button>
                        @endforeach
                    </div>
                    <input class="input h-8 w-48" type="search" placeholder="Path or exact IP" wire:model.live.debounce.400ms="search">
                </x-slot:actions>
                @include('livewire.websites.partials.monitoring-requests', ['rows' => $requests, 'showThreat' => true])
            </x-layer-card>

            <x-layer-card title="Top routes" helper="Busiest paths in this range." flush>
                @include('livewire.websites.partials.monitoring-routes', ['routes' => $routes])
            </x-layer-card>
        @endif

        {{-- Issues --}}
        @if ($view === 'issues')
            @if ($issue)
                <x-layer-card :title="$issue->title()" :helper="$issue->exception ?? $issue->level">
                    <x-slot:actions>
                        <button class="btn" type="button" wire:click="closeIssue"><x-menu-icon name="chevron-right" class="rotate-180" /> Back</button>
                        @if ($issue->status !== 'resolved')
                            <button class="btn btn-primary" type="button" wire:click="markIssue({{ $issue->id }}, 'resolved')"><x-menu-icon name="check" /> Resolve</button>
                        @endif
                        @if ($issue->status === 'open')
                            <button class="btn" type="button" wire:click="markIssue({{ $issue->id }}, 'ignored')">Ignore</button>
                        @else
                            <button class="btn" type="button" wire:click="markIssue({{ $issue->id }}, 'open')">Reopen</button>
                        @endif
                    </x-slot:actions>
                    <div class="flex flex-col gap-4">
                        <p class="break-words font-mono text-[13px]">{{ $issue->message }}</p>
                        <div class="mon-grid-4">
                            <div><p class="mon-label">Occurrences</p><p class="mon-value mon-value-sm">{{ number_format($issue->occurrences) }}</p></div>
                            <div><p class="mon-label">First seen</p><p class="mt-1.5 text-[13px]" title="@datetime($issue->first_seen_at)">@ago($issue->first_seen_at)</p></div>
                            <div><p class="mon-label">Last seen</p><p class="mt-1.5 text-[13px]" title="@datetime($issue->last_seen_at)">@ago($issue->last_seen_at)</p></div>
                            <div><p class="mon-label">Status</p><p class="mt-1.5"><span class="pill">{{ ucfirst($issue->status) }}</span></p></div>
                        </div>
                        @if ($issue->file)
                            <p class="text-[12.5px] text-muted">Thrown in <span class="font-mono text-ink">{{ $issue->file }}:{{ $issue->line }}</span></p>
                        @endif
                        @if ($issue->trace)
                            <div>
                                <p class="mon-label mb-2">Stack trace</p>
                                <pre class="mon-trace">{{ $issue->trace }}</pre>
                            </div>
                        @endif
                        <div class="flex gap-2">
                            <button class="btn" type="button" @click="setTab('logs'); Livewire.dispatch('open-log', { key: 'laravel' })"><x-menu-icon name="logs" /> Open Laravel log</button>
                        </div>
                    </div>
                </x-layer-card>
            @else
                <x-layer-card title="Issues" helper="{{ $website->isLaravel() ? 'ERROR and worse from the Laravel log, grouped by exception and the line that threw it.' : 'Issues are read from the Laravel log, so they are only tracked for Laravel sites.' }}" flush>
                    <x-slot:actions>
                        <div class="segmented" role="group" aria-label="Issue status">
                            @foreach (['open' => 'Open', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $key => $label)
                                <button type="button" wire:click="setIssueStatus('{{ $key }}')" aria-pressed="{{ $issueStatus === $key ? 'true' : 'false' }}" class="!flex items-center gap-1.5 text-[12px]">{{ $label }} <span class="text-muted">{{ $issueCounts[$key] ?? 0 }}</span></button>
                            @endforeach
                        </div>
                    </x-slot:actions>
                    @forelse ($issues as $row)
                        <div class="mon-issue" wire:key="issue-{{ $row->id }}">
                            <x-menu-icon name="bug" class="mt-0.5 shrink-0 {{ $row->status === 'open' ? 'text-[var(--mon-5xx)]' : 'text-muted' }}" />
                            <button class="min-w-0 flex-1 text-left" type="button" wire:click="openIssue({{ $row->id }})">
                                <span class="flex flex-wrap items-center gap-2">
                                    <span class="text-[13.5px] font-medium">{{ $row->title() }}</span>
                                    <span class="pill">{{ $row->level }}</span>
                                </span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">{{ $row->message }}</span>
                                @if ($row->file)
                                    <span class="mt-0.5 block truncate font-mono text-[11.5px] text-muted">{{ $row->file }}:{{ $row->line }}</span>
                                @endif
                            </button>
                            <div class="shrink-0 text-right text-[12px] text-muted">
                                <p class="font-medium text-ink">{{ number_format($row->occurrences) }}×</p>
                                <p title="@datetime($row->last_seen_at)">@ago($row->last_seen_at, true)</p>
                            </div>
                            <div class="flex shrink-0 gap-1">
                                @if ($row->status !== 'resolved')
                                    <button class="icon-btn" type="button" title="Resolve" wire:click="markIssue({{ $row->id }}, 'resolved')"><x-menu-icon name="check" /></button>
                                @else
                                    <button class="icon-btn" type="button" title="Reopen" wire:click="markIssue({{ $row->id }}, 'open')"><x-menu-icon name="refresh" /></button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="mon-empty">
                            <x-menu-icon name="check" class="size-5 text-[var(--mon-up)]" />
                            No {{ $issueStatus }} issues.
                        </div>
                    @endforelse
                </x-layer-card>
            @endif
        @endif

        {{-- Heartbeats --}}
        @if ($view === 'heartbeats')
            <x-layer-card title="Heartbeats" helper="Know when a cron job or scheduled task stops. Call the URL when the job finishes. No call within the interval (plus 2 minutes) sends a Telegram alert." flush>
                @forelse ($heartbeats as $heartbeat)
                    @php $state = $heartbeat->state(); @endphp
                    <div class="mon-issue flex-wrap items-center" wire:key="heartbeat-{{ $heartbeat->id }}" x-data="{ copied: false }">
                        <span class="status-badge w-24 justify-center" style="--status-color: {{ ['ok' => 'var(--mon-up)', 'late' => 'var(--mon-5xx)', 'waiting' => 'var(--color-muted)'][$state] }}">{{ ['ok' => 'On time', 'late' => 'Missed', 'waiting' => 'Waiting'][$state] }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] font-medium">{{ $heartbeat->name }} <span class="font-normal text-muted">· {{ \App\Models\Heartbeat::INTERVALS[$heartbeat->interval_minutes] ?? $heartbeat->interval_minutes.' min' }}</span></p>
                            <p class="text-[12px] text-muted">
                                {{ $heartbeat->last_ping_at ? 'Last ping '.app(\App\Services\DisplayTime::class)->ago($heartbeat->last_ping_at) : 'No ping yet' }}
                                · {{ ($state === 'late' ? 'was due ' : 'next due ').app(\App\Services\DisplayTime::class)->ago($heartbeat->dueAt()) }}
                            </p>
                            <code class="mt-1.5 block truncate rounded-md border border-line bg-recessed px-2 py-1 font-mono text-[11.5px]" title="{{ $heartbeat->url() }}">curl -fsS {{ $heartbeat->url() }}</code>
                        </div>
                        <div class="flex shrink-0 gap-1.5">
                            <button class="btn" type="button" @click="copyToClipboard(@js('curl -fsS '.$heartbeat->url()), 'Command copied.'); copied = true; setTimeout(() => copied = false, 1500)">
                                <x-menu-icon name="copy" /> <span x-text="copied ? 'Copied' : 'Copy command'"></span>
                            </button>
                            <button class="icon-btn icon-btn-danger" type="button" title="Delete" wire:click="deleteHeartbeat({{ $heartbeat->id }})" wire:confirm="Delete the heartbeat {{ $heartbeat->name }}?"><x-menu-icon name="trash" /></button>
                        </div>
                    </div>
                @empty
                    <div class="mon-empty"><x-menu-icon name="pulse" class="size-5" />No heartbeats yet. Add one below.</div>
                @endforelse
            </x-layer-card>

            <form wire:submit="addHeartbeat">
                <x-layer-card title="Add a heartbeat" helper="Example: end a crontab line with && curl -fsS the-url, or ping it from a Laravel scheduled task.">
                    <x-slot:actions>
                        <button class="btn btn-primary" type="submit"><x-menu-icon name="plus" /> Add</button>
                    </x-slot:actions>
                    <div class="grid gap-4 md:grid-cols-2">
                        <label class="field">
                            <span class="label">Name</span>
                            <input class="input" wire:model="heartbeat_name" placeholder="schedule:run, nightly backup" maxlength="80">
                            @error('heartbeat_name') <span class="error text-[12px]">{{ $message }}</span> @enderror
                        </label>
                        <label class="field">
                            <span class="label">It should run</span>
                            <select class="select" wire:model="heartbeat_interval">
                                @foreach (\App\Models\Heartbeat::INTERVALS as $minutes => $label)
                                    <option value="{{ $minutes }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('heartbeat_interval') <span class="error text-[12px]">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </x-layer-card>
            </form>
        @endif

        {{-- Security --}}
        @if ($view === 'security')
            <div class="mon-grid-4">
                <div class="mon-card"><p class="mon-label">Flagged requests</p><p class="mon-value">{{ $num($threats['total']) }}</p></div>
                <div class="mon-card"><p class="mon-label"><span class="mon-dot is-5xx mr-1.5"></span>Critical</p><p class="mon-value">{{ $num($threats['levels']['critical']) }}</p></div>
                <div class="mon-card"><p class="mon-label"><span class="mon-dot is-p95 mr-1.5"></span>High</p><p class="mon-value">{{ $num($threats['levels']['high']) }}</p></div>
                <div class="mon-card"><p class="mon-label"><span class="mon-dot is-4xx mr-1.5"></span>Medium</p><p class="mon-value">{{ $num($threats['levels']['medium']) }}</p></div>
            </div>

            <div class="mon-grid-2 mon-grid-side">
                <div class="mon-card">
                    <p class="mon-label">Threat timeline</p>
                    <x-monitor.spark :values="collect($threats['buckets'])->pluck('count')->all()" class="is-danger mt-4 !h-28" />
                    <div class="mon-axis">
                        <span>{{ $threats['buckets'][0]['label'] ?? '' }}</span>
                        <span>{{ $threats['buckets'][count($threats['buckets']) - 1]['label'] ?? '' }}</span>
                    </div>
                </div>
                <div class="mon-card">
                    <p class="mon-label">By type</p>
                    <div class="mt-3 flex flex-col gap-2.5">
                        @php $typeTotal = max(1, array_sum($threats['categories'])); @endphp
                        @forelse ($threats['categories'] as $category => $count)
                            <div>
                                <div class="mb-1 flex justify-between text-[12.5px]"><span class="font-medium">{{ ThreatDetector::CATEGORIES[$category] ?? $category }}</span><span class="text-muted">{{ $num($count) }}</span></div>
                                <div class="mon-meter"><span style="width: {{ round($count / $typeTotal * 100) }}%; background: var(--mon-5xx)"></span></div>
                            </div>
                        @empty
                            <p class="text-[12.5px] text-muted">Nothing suspicious in this range.</p>
                        @endforelse
                    </div>
                </div>
            </div>

            <x-layer-card title="Suspicious IPs" helper="Riskiest first. Blocking adds the IP to this site's nginx rules (also behind a Cloudflare tunnel)." flush>
                @if ($attackers->isEmpty())
                    <div class="mon-empty"><x-menu-icon name="shield" class="size-5 text-[var(--mon-up)]" />No suspicious IPs in this range.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table mon-table">
                            <thead><tr><th>IP</th><th>Risk</th><th>Types</th><th class="text-right">Requests</th><th>Last seen</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($attackers as $attacker)
                                    @php $level = ThreatDetector::level((int) $attacker->risk); $isBlocked = in_array($attacker->ip, $blockedIps, true); @endphp
                                    <tr wire:key="attacker-{{ $attacker->ip }}">
                                        <td class="mono">{{ $attacker->ip }} @if ($attacker->country)<span class="text-muted">· {{ $attacker->country }}</span>@endif</td>
                                        <td><span class="mon-risk is-{{ $level }}">{{ $level }}</span></td>
                                        <td class="max-w-[260px] truncate text-[12.5px] text-muted">{{ ThreatDetector::label($attacker->threats) }}</td>
                                        <td class="text-right tabular-nums">{{ number_format($attacker->hits) }}</td>
                                        <td class="text-[12.5px] text-muted"><span title="@datetime($attacker->last_at)">@ago($attacker->last_at, true)</span></td>
                                        <td class="text-right">
                                            @if ($isBlocked)
                                                <button class="btn" type="button" wire:click="unblockIp(@js($attacker->ip))">Unblock</button>
                                            @else
                                                <button class="btn btn-danger" type="button" wire:click="blockIp(@js($attacker->ip))" wire:confirm="Block {{ $attacker->ip }} on {{ $website->name }}? nginx will answer it with 403.">
                                                    <x-menu-icon name="ban" /> Block
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-layer-card>

            @if ($blockedIps !== [])
                <x-layer-card title="Blocked IPs" helper="Also editable in the Security tab." flush>
                    <div class="flex flex-wrap gap-2 p-4">
                        @foreach ($blockedIps as $ip)
                            <span class="tag-neutral inline-flex items-center gap-1.5 font-mono" wire:key="blocked-{{ $ip }}">
                                {{ $ip }}
                                <button type="button" class="text-muted hover:text-ink" title="Unblock" wire:click="unblockIp(@js($ip))"><x-menu-icon name="close" class="size-3.5" /></button>
                            </span>
                        @endforeach
                    </div>
                </x-layer-card>
            @endif

            <x-layer-card title="Flagged requests" helper="Each request is checked for SQL injection, XSS, path traversal, file inclusion, command injection, scanners, and attack tools." flush>
                @include('livewire.websites.partials.monitoring-requests', ['rows' => $threatRequests, 'showThreat' => true])
            </x-layer-card>
        @endif
    </div>
</div>
