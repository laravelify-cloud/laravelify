@php
    use App\Livewire\Monitoring\Errors;
    use App\Services\Glitchtip;

    $embedded = $website !== null;
    $ms = fn (?float $value): string => $value === null ? '—' : ($value >= 1000 ? rtrim(rtrim(number_format($value / 1000, 2), '0'), '.').'s' : number_format($value, $value < 10 ? 1 : 0).'ms');
    $num = fn (int|float|null $value): string => match (true) {
        $value === null => '—',
        $value >= 1000000 => round($value / 1000000, 1).'M',
        $value >= 10000 => round($value / 1000, 1).'k',
        default => number_format($value),
    };
    $levelClass = fn (string $level): string => 'is-'.match (strtolower($level)) {
        'fatal', 'critical' => 'fatal',
        'error' => 'error',
        'warning', 'warn' => 'warning',
        'info', 'log' => 'info',
        default => 'debug',
    };
    $projectName = fn (int $id): string => $projects[$id] ?? 'Project '.$id;
    $listing = $issue === null && $transaction === null;
@endphp

<div @class(['gt-page flex flex-col', 'gap-6' => ! $embedded, 'gap-4' => $embedded])>
    @if ($installed && $connected && $listing)
        <span hidden wire:poll.30s></span>
    @endif

    @unless ($embedded)
        <x-page-header title="Monitoring" summary="Errors, slow pages, and logs that your websites send to error tracking (GlitchTip), right here in the panel." />
        @include('livewire.monitoring.partials.tabs', ['active' => $section])
    @else
        <div class="segmented self-start" role="tablist" aria-label="Error tracking">
            @foreach (Errors::SECTIONS as $key => $label)
                <button type="button" wire:click="setSection('{{ $key }}')" aria-pressed="{{ $section === $key ? 'true' : 'false' }}" class="text-[12.5px] font-medium">{{ $label }}</button>
            @endforeach
        </div>
    @endunless

    @if (! $installed)
        <div class="mon-card gt-empty">
            <div class="stat-icon"><x-menu-icon name="bug" /></div>
            <div class="min-w-0 flex-1">
                <p class="font-medium">Error tracking is off</p>
                <p class="mt-0.5 text-[13px] text-muted">Turn it on from any website's GlitchTip tab. Then every website's errors, slow pages, and logs show here.</p>
            </div>
        </div>
    @elseif (! $connected)
        <div class="mon-card gt-empty">
            <div class="stat-icon"><x-menu-icon name="bug" /></div>
            <div class="min-w-0 flex-1">
                <p class="font-medium">This website is not connected yet</p>
                <p class="mt-0.5 text-[13px] text-muted">Connect it below: it gets its own project and key, and its errors show here.</p>
            </div>
        </div>
    @elseif ($error)
        <x-callout type="danger" title="Could not reach error tracking">
            {{ \Illuminate\Support\Str::limit($error, 200) }}
        </x-callout>
    @elseif ($issue)
        {{-- One issue: what broke, where, and what happened before --}}
        <div class="flex flex-col gap-4" wire:key="issue-{{ $issue['id'] }}">
            <button type="button" class="gt-back" wire:click="back"><x-menu-icon name="chevron-right" class="size-4 rotate-180" /> All issues</button>

            <div class="mon-card">
                <div class="flex flex-wrap items-start gap-4">
                    <span class="gt-level-bar {{ $levelClass($issue['level']) }}"></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 text-[12px] text-muted">
                            <span class="gt-level {{ $levelClass($issue['level']) }}">{{ $issue['level'] }}</span>
                            <span class="font-mono">{{ $issue['shortId'] }}</span>
                            @unless ($embedded)<span>· {{ $issue['project'] }}</span>@endunless
                            <span @class(['gt-status', 'is-'.$issue['status']])>{{ $issue['status'] }}</span>
                        </div>
                        <h2 class="gt-issue-title mt-2">{{ $issue['title'] }}</h2>
                        @if ($issue['culprit'])
                            <p class="mt-1 font-mono text-[12.5px] text-muted">{{ $issue['culprit'] }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($issue['status'] === 'unresolved')
                            <button class="btn btn-primary" type="button" wire:click="markIssues('resolved', {{ $issue['id'] }})"><x-menu-icon name="check" /> Resolve</button>
                            <button class="btn" type="button" wire:click="markIssues('ignored', {{ $issue['id'] }})"><x-menu-icon name="eye-off" /> Ignore</button>
                        @else
                            <button class="btn" type="button" wire:click="markIssues('unresolved', {{ $issue['id'] }})"><x-menu-icon name="restore" /> Reopen</button>
                        @endif
                        <a class="btn" href="{{ $issue['url'] }}" target="_blank" rel="noopener" title="Open in GlitchTip"><x-menu-icon name="external" /></a>
                    </div>
                </div>

                <div class="gt-facts mt-5">
                    <div><p class="mon-label">Events</p><p class="mon-value mon-value-sm">{{ $num($issue['count']) }}</p></div>
                    <div><p class="mon-label">Users</p><p class="mon-value mon-value-sm">{{ $num($issue['users']) }}</p></div>
                    <div><p class="mon-label">First seen</p><p class="gt-fact" title="@datetime($issue['firstSeen'])">@ago($issue['firstSeen'])</p></div>
                    <div><p class="mon-label">Last seen</p><p class="gt-fact" title="@datetime($issue['lastSeen'])">@ago($issue['lastSeen'])</p></div>
                    <div><p class="mon-label">Release</p><p class="gt-fact font-mono">{{ $issue['lastRelease'] ?? '—' }}</p></div>
                </div>

                @if (array_sum($issue['trend']) > 0)
                    <div class="mt-5">
                        <div class="flex items-center justify-between">
                            <p class="mon-label">Events · {{ $period === '14d' ? 'last 14 days' : 'last 24 hours' }}</p>
                            <div class="segmented" role="group">
                                @foreach (['24h', '14d'] as $key)
                                    <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}" class="font-mono text-[11.5px] font-semibold">{{ $key }}</button>
                                @endforeach
                            </div>
                        </div>
                        @include('livewire.monitoring.partials.trend', ['points' => $issue['trend'], 'tall' => true])
                    </div>
                @endif
            </div>

            @foreach ($issue['exceptions'] as $exception)
                <x-layer-card :title="$exception['type'] ?: 'Exception'" :helper="$exception['value']" flush>
                    @php
                        $appFrames = collect($exception['frames'])->where('inApp', true)->count();
                    @endphp
                    <div class="gt-frames" x-data="{ all: {{ $appFrames === 0 ? 'true' : 'false' }} }">
                        @foreach ($exception['frames'] as $index => $frame)
                            <details class="gt-frame" @if ($frame['inApp']) open @else x-show="all" x-cloak @endif>
                                <summary>
                                    <span @class(['gt-frame-tag', 'is-app' => $frame['inApp']])>{{ $frame['inApp'] ? 'app' : 'vendor' }}</span>
                                    <span class="min-w-0 truncate font-mono">{{ $frame['file'] }}@if ($frame['line'])<span class="text-muted">:{{ $frame['line'] }}</span>@endif</span>
                                    @if ($frame['function'])
                                        <span class="gt-frame-fn">in <b>{{ $frame['function'] }}</b></span>
                                    @endif
                                </summary>
                                @if ($frame['context'] !== [])
                                    <pre class="gt-code">@foreach ($frame['context'] as $line)<span @class(['is-hit' => $line['line'] === $frame['line']])><i>{{ $line['line'] }}</i>{{ $line['code'] }}</span>
@endforeach</pre>
                                @endif
                            </details>
                        @endforeach
                        @if ($appFrames > 0 && $appFrames < count($exception['frames']))
                            <button type="button" class="gt-frames-toggle" @click="all = !all" x-text="all ? 'Hide framework frames' : 'Show {{ count($exception['frames']) - $appFrames }} framework frames'"></button>
                        @endif
                    </div>
                </x-layer-card>
            @endforeach

            @if ($issue['exceptions'] === [] && $issue['message'])
                <x-layer-card title="Message">
                    <pre class="gt-message">{{ $issue['message'] }}</pre>
                </x-layer-card>
            @endif

            <div class="mon-grid-side grid gap-4">
                <x-layer-card title="Breadcrumbs" helper="What happened just before, oldest first." flush>
                    @forelse ($issue['breadcrumbs'] as $crumb)
                        <div class="gt-crumb">
                            <span class="gt-dot {{ $levelClass($crumb['level']) }}"></span>
                            <span class="gt-crumb-cat">{{ $crumb['category'] ?: 'event' }}</span>
                            <span class="min-w-0 flex-1 break-words font-mono text-[12.5px]">{{ $crumb['message'] }}</span>
                        </div>
                    @empty
                        <div class="table-empty">No breadcrumbs on this event.</div>
                    @endforelse
                </x-layer-card>

                <x-layer-card title="Latest event" :helper="$issue['eventAt'] ? 'Sent '.app(\App\Services\DisplayTime::class)->ago($issue['eventAt']) : null">
                    @if ($issue['request'])
                        <p class="mon-label">Request</p>
                        <p class="mt-1 mb-4 break-all font-mono text-[12.5px]"><b>{{ $issue['request']['method'] }}</b> {{ $issue['request']['url'] }}</p>
                    @endif
                    <p class="mon-label">Tags</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @forelse ($issue['tags'] as $key => $value)
                            <span class="gt-tag"><span>{{ $key }}</span>{{ $value }}</span>
                        @empty
                            <span class="text-[12.5px] text-muted">None</span>
                        @endforelse
                    </div>
                </x-layer-card>
            </div>
        </div>
    @elseif ($transaction)
        {{-- One transaction: its speed over time and where the time goes --}}
        @php
            $group = $transaction['group'];
            $spanTotal = max(1, collect($transaction['spans'])->sum('total'));
        @endphp
        <div class="flex flex-col gap-4" wire:key="transaction-{{ $group['id'] }}">
            <button type="button" class="gt-back" wire:click="back"><x-menu-icon name="chevron-right" class="size-4 rotate-180" /> All transactions</button>

            <div class="mon-card">
                <div class="flex flex-wrap items-center gap-2 text-[12px] text-muted">
                    @if ($group['method'])<span class="gt-method">{{ $group['method'] }}</span>@endif
                    <span class="font-mono">{{ $group['op'] }}</span>
                    @unless ($embedded)<span>· {{ $projectName($group['projectId']) }}</span>@endunless
                </div>
                <h2 class="gt-issue-title mt-2 font-mono">{{ $group['name'] }}</h2>
                <div class="gt-facts mt-5">
                    <div><p class="mon-label">Runs</p><p class="mon-value mon-value-sm">{{ $num($group['count']) }}</p></div>
                    <div><p class="mon-label">Average</p><p class="mon-value mon-value-sm">{{ $ms($group['avg']) }}</p></div>
                    <div><p class="mon-label">p50</p><p class="mon-value mon-value-sm">{{ $ms($group['p50']) }}</p></div>
                    <div><p class="mon-label">p95</p><p class="mon-value mon-value-sm" style="color: var(--mon-p95)">{{ $ms($group['p95']) }}</p></div>
                    <div><p class="mon-label">Error rate</p><p class="mon-value mon-value-sm" @style(['color: var(--mon-5xx)' => $group['errorRate'] > 0])>{{ round($group['errorRate'] * 100, 1) }}%</p></div>
                </div>

                @if ($transaction['trend'] !== [])
                    <div class="mt-5">
                        <p class="mon-label">Average duration over time</p>
                        @include('livewire.monitoring.partials.trend', ['points' => array_map(fn ($point) => (int) round($point['avg']), $transaction['trend']), 'tall' => true, 'unit' => 'ms'])
                    </div>
                @endif
            </div>

            <x-layer-card title="Where the time goes" helper="Database queries, HTTP calls, and other spans inside this transaction, by total time." flush>
                @if ($transaction['spans'] === [])
                    <div class="table-empty">No spans in this range yet. They show once the SDK sends traces with spans (performance monitoring on).</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table mon-table">
                            <thead><tr><th>Span</th><th class="text-right">Count</th><th class="text-right">Avg</th><th class="text-right">p95</th><th class="w-[28%]">Share of time</th></tr></thead>
                            <tbody>
                                @foreach ($transaction['spans'] as $span)
                                    <tr wire:key="span-{{ $loop->index }}">
                                        <td class="max-w-[420px]">
                                            <span class="gt-op">{{ $span['op'] }}</span>
                                            <span class="block truncate font-mono text-[12.5px]" title="{{ $span['description'] }}">{{ $span['description'] }}</span>
                                        </td>
                                        <td class="text-right tabular-nums">{{ $num($span['count']) }}</td>
                                        <td class="text-right tabular-nums">{{ $ms($span['avg']) }}</td>
                                        <td class="text-right tabular-nums">{{ $ms($span['p95']) }}</td>
                                        <td><div class="mon-meter"><span style="width: {{ max(2, round($span['total'] / $spanTotal * 100)) }}%"></span></div></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-layer-card>
        </div>
    @else
        {{-- Filters --}}
        <div class="gt-filters">
            @if ($section === 'issues')
                <div class="segmented" role="tablist" aria-label="Issue status">
                    @foreach (['unresolved' => 'Unresolved', 'resolved' => 'Resolved', 'ignored' => 'Ignored'] as $key => $label)
                        <button type="button" wire:click="setStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" class="text-[12.5px] font-medium">{{ $label }}</button>
                    @endforeach
                </div>
            @endif

            <label class="gt-search">
                <x-menu-icon name="search" class="size-4 text-muted" />
                <input class="input" type="search" wire:model.live.debounce.400ms="search" placeholder="{{ ['issues' => 'Search errors…', 'performance' => 'Search routes and jobs…', 'logs' => 'Search log messages…'][$section] }}">
            </label>

            @if (! $embedded && count($projects) > 1)
                <select class="select input gt-select" wire:model.live="project" aria-label="Website">
                    <option value="">All websites</option>
                    @foreach ($projects as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @endif

            @if ($section !== 'logs')
                <select class="select input gt-select" wire:model.live="sort" aria-label="Sort">
                    @foreach ($section === 'issues' ? Glitchtip::ISSUE_SORTS : Glitchtip::TRANSACTION_SORTS as $key => $label)
                        <option value="{{ $loop->first ? '' : $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endif

            <div class="segmented ml-auto" role="group" aria-label="{{ $section === 'issues' ? 'Trend' : 'Time range' }}">
                @foreach ($section === 'issues' ? ['24h', '14d'] : array_keys(Glitchtip::PERIODS) as $key)
                    <button type="button" wire:click="setPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}" class="font-mono text-[11.5px] font-semibold">{{ $key }}</button>
                @endforeach
            </div>
        </div>

        @if ($section === 'issues')
            {{-- Issues --}}
            <div class="gt-list" x-data>
                <div class="gt-list-head">
                    <label class="gt-check" title="Select this page">
                        <input type="checkbox"
                            @checked($page['items'] !== [] && count($selected) === count($page['items']))
                            wire:click="$set('selected', {{ count($selected) === count($page['items']) ? '[]' : json_encode(array_map(fn ($row) => (string) $row['id'], $page['items'])) }})"
                            @disabled($page['items'] === [])>
                    </label>
                    @if ($selected !== [])
                        <span class="text-[13px] font-medium">{{ count($selected) }} selected</span>
                        <div class="flex flex-wrap gap-1.5">
                            @if ($status !== 'resolved')<button class="btn btn-sm" type="button" wire:click="markIssues('resolved')"><x-menu-icon name="check" /> Resolve</button>@endif
                            @if ($status !== 'ignored')<button class="btn btn-sm" type="button" wire:click="markIssues('ignored')"><x-menu-icon name="eye-off" /> Ignore</button>@endif
                            @if ($status !== 'unresolved')<button class="btn btn-sm" type="button" wire:click="markIssues('unresolved')"><x-menu-icon name="restore" /> Reopen</button>@endif
                        </div>
                    @else
                        <span class="flex-1 text-[12px] font-semibold uppercase tracking-[0.08em] text-muted">Issue</span>
                        <span class="gt-col-trend mon-label max-md:hidden">Trend · {{ $period }}</span>
                        <span class="gt-col-num mon-label">Events</span>
                        <span class="gt-col-num mon-label max-sm:hidden">Users</span>
                    @endif
                </div>

                @forelse ($page['items'] as $row)
                    <div @class(['gt-row', 'is-selected' => in_array((string) $row['id'], $selected, true)]) wire:key="gt-issue-{{ $row['id'] }}">
                        <span class="gt-level-bar {{ $levelClass($row['level']) }}"></span>
                        <label class="gt-check" @click.stop>
                            <input type="checkbox" value="{{ $row['id'] }}" wire:model.live="selected">
                        </label>
                        <button type="button" class="gt-row-main" wire:click="openIssue({{ $row['id'] }})">
                            <span class="gt-row-title">
                                @if ($row['type'] && str_starts_with($row['title'], $row['type'].':'))
                                    <b>{{ $row['type'] }}</b><span>{{ \Illuminate\Support\Str::after($row['title'], $row['type'].':') }}</span>
                                @else
                                    <b>{{ $row['title'] }}</b>
                                @endif
                            </span>
                            <span class="gt-row-meta">
                                <span class="gt-level {{ $levelClass($row['level']) }}">{{ $row['level'] }}</span>
                                @unless ($embedded)<span class="font-medium text-ink-dim">{{ $row['project'] }}</span>@endunless
                                @if ($row['culprit'])<span class="truncate font-mono">{{ $row['culprit'] }}</span>@endif
                                <span class="whitespace-nowrap" title="First seen @datetime($row['firstSeen'])"><x-menu-icon name="clock" class="size-3.5" /> @ago($row['firstSeen'], true) old</span>
                                <span class="whitespace-nowrap" title="Last seen @datetime($row['lastSeen'])">seen @ago($row['lastSeen'])</span>
                                @if ($row['release'])<span class="whitespace-nowrap font-mono">{{ $row['release'] }}</span>@endif
                            </span>
                        </button>
                        <span class="gt-col-trend max-md:hidden">@include('livewire.monitoring.partials.trend', ['points' => $row['trend']])</span>
                        <span class="gt-col-num gt-count">{{ $num($row['count']) }}</span>
                        <span class="gt-col-num text-muted max-sm:hidden">{{ $num($row['users']) }}</span>
                    </div>
                @empty
                    <div class="gt-none">
                        <x-menu-icon name="check" class="size-6 text-[var(--mon-up)]" />
                        <p class="font-medium">{{ $search !== '' ? 'No issues match that search.' : ['unresolved' => 'No open issues. Nice!', 'resolved' => 'Nothing resolved yet.', 'ignored' => 'Nothing ignored.'][$status] }}</p>
                        @if ($search === '' && $status === 'unresolved')
                            <p class="text-[12.5px] text-muted">New errors from your websites show here within seconds.</p>
                        @endif
                    </div>
                @endforelse

                @include('livewire.monitoring.partials.pager', ['page' => $page, 'noun' => 'issue'])
            </div>
        @elseif ($section === 'performance')
            {{-- Performance --}}
            @php
                $rows = collect($page['items']);
                $runs = $rows->sum('count');
                $slowest = max(1, $rows->max(fn ($row) => $row['p95'] ?? $row['avg']) ?? 1);
            @endphp
            <div class="mon-grid-4">
                <div class="mon-card"><p class="mon-label">Runs · {{ $period }}</p><p class="mon-value">{{ $num($runs) }}</p><p class="mon-sub">{{ $rows->count() }} {{ str('transaction')->plural($rows->count()) }} on this page</p></div>
                <div class="mon-card"><p class="mon-label">Average</p><p class="mon-value">{{ $runs > 0 ? $ms($rows->sum(fn ($row) => $row['avg'] * $row['count']) / $runs) : '—' }}</p><p class="mon-sub">Weighted by runs</p></div>
                <div class="mon-card"><p class="mon-label">Slowest p95</p><p class="mon-value" style="color: var(--mon-p95)">{{ $rows->isEmpty() ? '—' : $ms($rows->max('p95')) }}</p><p class="mon-sub truncate">{{ $rows->sortByDesc('p95')->first()['name'] ?? 'No transactions' }}</p></div>
                <div class="mon-card"><p class="mon-label">Errors</p><p class="mon-value" @style(['color: var(--mon-5xx)' => $rows->sum('errors') > 0])>{{ $num($rows->sum('errors')) }}</p><p class="mon-sub">Failed runs on this page</p></div>
            </div>

            <div class="gt-list">
                @if ($rows->isEmpty())
                    <div class="gt-none">
                        <x-menu-icon name="bolt" class="size-6 text-muted" />
                        <p class="font-medium">{{ $search !== '' ? 'No transactions match that search.' : 'No performance data in this range' }}</p>
                        <p class="text-[12.5px] text-muted">Turn on tracing in the website's Sentry SDK (traces_sample_rate) and every page load and job shows here with its timing.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table mon-table gt-table">
                            <thead>
                                <tr>
                                    <th>Transaction</th>
                                    <th class="text-right">Runs</th>
                                    <th class="text-right">Avg</th>
                                    <th class="text-right max-md:hidden">p50</th>
                                    <th class="text-right">p95</th>
                                    <th class="text-right max-md:hidden">Errors</th>
                                    <th class="w-[18%] max-lg:hidden">Speed</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr class="cursor-pointer" wire:click="openTransaction({{ $row['id'] }})" wire:key="gt-tx-{{ $row['id'] }}">
                                        <td class="max-w-[460px]">
                                            <div class="flex min-w-0 items-center gap-2">
                                                @if ($row['method'])<span class="gt-method">{{ $row['method'] }}</span>@endif
                                                <span class="truncate font-mono text-[13px] font-medium" title="{{ $row['name'] }}">{{ $row['method'] ? \Illuminate\Support\Str::after($row['name'], $row['method'].' ') : $row['name'] }}</span>
                                            </div>
                                            <span class="mt-0.5 block text-[12px] text-muted">{{ $row['op'] }}@unless ($embedded) · {{ $projectName($row['projectId']) }}@endunless · seen @ago($row['lastSeen'])</span>
                                        </td>
                                        <td class="text-right tabular-nums">{{ $num($row['count']) }}</td>
                                        <td class="text-right tabular-nums">{{ $ms($row['avg']) }}</td>
                                        <td class="text-right tabular-nums text-muted max-md:hidden">{{ $ms($row['p50']) }}</td>
                                        <td class="text-right tabular-nums font-medium" @style(['color: var(--mon-p95)' => ($row['p95'] ?? 0) >= 1000])>{{ $ms($row['p95']) }}</td>
                                        <td class="text-right tabular-nums max-md:hidden" @style(['color: var(--mon-5xx)' => $row['errors'] > 0])>{{ $row['errors'] > 0 ? round($row['errorRate'] * 100, 1).'%' : '—' }}</td>
                                        <td class="max-lg:hidden">
                                            <div class="gt-speed" title="avg {{ $ms($row['avg']) }} · p95 {{ $ms($row['p95']) }}">
                                                <span class="is-p95" style="width: {{ max(2, round(($row['p95'] ?? $row['avg']) / $slowest * 100)) }}%"></span>
                                                <span class="is-avg" style="width: {{ max(2, round($row['avg'] / $slowest * 100)) }}%"></span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @include('livewire.monitoring.partials.pager', ['page' => $page, 'noun' => 'transaction'])
            </div>
        @else
            {{-- Logs --}}
            @php
                $series = $logStats['series'] ?? [];
                $intervals = $logStats['intervals'] ?? [];
                $order = ['fatal', 'error', 'warn', 'info', 'debug', 'trace'];
                $totals = collect($order)->mapWithKeys(fn ($level) => [$level => array_sum($series[$level] ?? [])])->filter();
                $columns = collect($intervals)->map(fn ($at, $index) => ['at' => $at, 'counts' => collect($order)->mapWithKeys(fn ($level) => [$level => $series[$level][$index] ?? 0])->all()]);
                $peak = max(1, $columns->max(fn ($column) => array_sum($column['counts'])) ?? 1);
            @endphp
            <div class="mon-card">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="mon-label">Log lines · {{ $period }}</p>
                        <p class="mon-value">{{ $num($totals->sum()) }}</p>
                    </div>
                    <div class="flex flex-wrap gap-1.5" role="group" aria-label="Levels">
                        @foreach (['error', 'warn', 'info', 'debug'] as $level)
                            <button type="button" wire:click="toggleLevel('{{ $level }}')" @class(['gt-level-chip', $levelClass($level), 'is-on' => in_array($level, $levels, true)]) aria-pressed="{{ in_array($level, $levels, true) ? 'true' : 'false' }}">
                                <span class="gt-dot {{ $levelClass($level) }}"></span>{{ $level }} <b>{{ $num($totals[$level] ?? 0) }}</b>
                            </button>
                        @endforeach
                    </div>
                </div>
                @if ($columns->isNotEmpty())
                    <div class="gt-log-chart mt-4" aria-hidden="true">
                        @foreach ($columns as $column)
                            <span title="{{ app(\App\Services\DisplayTime::class)->label($column['at']) }}: {{ collect($column['counts'])->filter()->map(fn ($count, $level) => $count.' '.$level)->implode(', ') ?: 'nothing' }}">
                                @foreach ($column['counts'] as $level => $count)
                                    @if ($count > 0)<i class="{{ $levelClass($level) }}" style="height: {{ max(3, $count / $peak * 100) }}%"></i>@endif
                                @endforeach
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="gt-list">
                @forelse ($page['items'] as $log)
                    <details class="gt-log" wire:key="gt-log-{{ $log['id'] }}">
                        <summary>
                            <span class="gt-log-time" title="@datetime($log['at'])">{{ app(\App\Services\DisplayTime::class)->clock($log['at']) }}</span>
                            <span class="gt-level {{ $levelClass($log['level']) }}">{{ $log['level'] }}</span>
                            @unless ($embedded)<span class="gt-log-project">{{ $projectName($log['projectId']) }}</span>@endunless
                            <span class="gt-log-body">{{ $log['body'] }}</span>
                        </summary>
                        <div class="gt-log-more">
                            <pre class="gt-message">{{ $log['body'] }}</pre>
                            <dl class="gt-kv">
                                <dt>Time</dt><dd>@datetime($log['at'])</dd>
                                @if ($log['environment'])<dt>Environment</dt><dd>{{ $log['environment'] }}</dd>@endif
                                @if ($log['service'])<dt>Service</dt><dd>{{ $log['service'] }}</dd>@endif
                                @if ($log['host'])<dt>Host</dt><dd>{{ $log['host'] }}</dd>@endif
                                @if ($log['traceId'])<dt>Trace</dt><dd class="font-mono">{{ $log['traceId'] }}</dd>@endif
                                @foreach ($log['data'] as $key => $value)
                                    <dt>{{ $key }}</dt><dd class="font-mono">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                @endforeach
                            </dl>
                        </div>
                    </details>
                @empty
                    <div class="gt-none">
                        <x-menu-icon name="logs" class="size-6 text-muted" />
                        <p class="font-medium">{{ $search !== '' || $levels !== [] ? 'No log lines match.' : 'No logs in this range' }}</p>
                        <p class="text-[12.5px] text-muted">Logs show here when the website's Sentry SDK sends them (enable_logs on).</p>
                    </div>
                @endforelse
                @include('livewire.monitoring.partials.pager', ['page' => $page, 'noun' => 'log line'])
            </div>
        @endif
    @endif
</div>
