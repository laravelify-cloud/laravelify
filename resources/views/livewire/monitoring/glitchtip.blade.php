@php
    $ms = fn (?float $value): string => $value === null ? '—' : ($value >= 1000 ? rtrim(rtrim(number_format($value / 1000, 2), '0'), '.').' s' : number_format($value).' ms');
    $num = fn (int|float $value): string => $value >= 1000000 ? round($value / 1000000, 1).'M' : ($value >= 1000 ? round($value / 1000, 1).'k' : (string) $value);
    $levelColor = fn (string $level): string => match ($level) {
        'fatal', 'error' => 'var(--mon-5xx)',
        'warning', 'warn' => 'var(--mon-p95)',
        'info' => '#3b82f6',
        default => 'var(--color-muted)',
    };
@endphp

<div class="flex flex-col gap-4" @if ($available && $section === 'logs') wire:poll.30s.visible @endif>
    @if (! $available)
        <div class="mon-card gt-empty">
            <x-menu-icon name="bug" class="size-6" />
            <div>
                <p class="text-[15px] font-semibold">Error tracking is off</p>
                <p class="mt-1 text-[13px] text-muted">Issues, performance and logs come from GlitchTip. Turn it on from any website's <b>GlitchTip</b> tab: it installs once for the whole server and connects every website.</p>
            </div>
        </div>
    @else
        @if ($lockedProject !== null)
            <nav class="gt-tabs" role="tablist" aria-label="Error tracking sections">
                @foreach (['issues' => ['Issues', 'bug'], 'performance' => ['Performance', 'chart'], 'logs' => ['Logs', 'logs']] as $key => [$label, $icon])
                    <button type="button" role="tab" aria-selected="{{ $section === $key ? 'true' : 'false' }}" wire:click="showSection('{{ $key }}')">
                        <x-menu-icon name="{{ $icon }}" class="size-4" /> {{ $label }}
                    </button>
                @endforeach
            </nav>
        @endif

        {{-- One filter bar for the three sections --}}
        <div class="gt-toolbar">
            @if ($lockedProject === null)
                <label class="gt-field">
                    <span>Website</span>
                    <select class="input" wire:model.live="project">
                        <option value="">All websites</option>
                        @foreach ($projects as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($section === 'issues')
                <label class="gt-field">
                    <span>Status</span>
                    <select class="input" wire:model.live="status">
                        <option value="unresolved">Open</option>
                        <option value="resolved">Resolved</option>
                        <option value="ignored">Ignored</option>
                    </select>
                </label>
            @elseif ($section === 'performance')
                <label class="gt-field">
                    <span>Sort</span>
                    <select class="input" wire:model.live="sort">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <label class="gt-field gt-grow">
                <span>Search</span>
                <input class="input" type="search" wire:model.live.debounce.400ms="query" placeholder="{{ ['issues' => 'Message, exception or file', 'performance' => 'Route or job, like /checkout', 'logs' => 'Text in the log line'][$section] }}">
            </label>

            <div class="gt-field">
                <span>Period</span>
                <div class="segmented" role="group" aria-label="Period">
                    @foreach ($periods as $value)
                        <button type="button" aria-pressed="{{ $period === $value ? 'true' : 'false' }}" wire:click="$set('period', '{{ $value }}')">{{ $value }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($error)
            <div class="mon-card text-[13px]" style="color: var(--mon-5xx)">{{ $error }}</div>
        @elseif ($rows === [])
            <div class="mon-card gt-empty">
                <x-menu-icon name="{{ ['issues' => 'check', 'performance' => 'chart', 'logs' => 'logs'][$section] }}" class="size-6" />
                <div>
                    <p class="text-[15px] font-semibold">{{ ['issues' => $status === 'unresolved' ? 'No open issues' : 'Nothing here', 'performance' => 'No transactions yet', 'logs' => 'No logs yet'][$section] }}</p>
                    <p class="mt-1 text-[13px] text-muted">{{ [
                        'issues' => 'Errors your websites send to GlitchTip show up here, grouped by cause.',
                        'performance' => 'Turn on tracing in the Sentry SDK (traces_sample_rate) and each route, job and command shows its speed here.',
                        'logs' => 'Send logs with the Sentry SDK (enable_logs) and they show up here, searchable.',
                    ][$section] }} Try a longer period too.</p>
                </div>
            </div>
        @elseif ($section === 'issues')
            <div class="gt-list" wire:loading.class="opacity-60" wire:target="project,period,query,status,goTo">
                <div class="gt-head gt-issue-row">
                    <span>Issue</span><span class="text-right">Events</span><span class="text-right">Users</span><span>Last seen</span>
                </div>
                @foreach ($rows as $issue)
                    <div wire:key="issue-{{ $issue['id'] }}" class="gt-item {{ $open === $issue['id'] ? 'is-open' : '' }}">
                        <button type="button" class="gt-issue-row" wire:click="toggleIssue('{{ $issue['id'] }}')" aria-expanded="{{ $open === $issue['id'] ? 'true' : 'false' }}">
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    <span class="gt-level" style="--level: {{ $levelColor($issue['level']) }}">{{ $issue['level'] }}</span>
                                    <span class="truncate font-semibold">{{ $issue['type'] !== '' ? $issue['type'] : $issue['title'] }}</span>
                                </span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">
                                    @if ($issue['type'] !== '') {{ \Illuminate\Support\Str::after($issue['title'], ': ') }} · @endif
                                    <span class="font-mono">{{ $issue['culprit'] }}</span>
                                </span>
                                <span class="mt-1 block text-[11.5px] text-muted">@if ($lockedProject === null){{ $issue['website'] }} · @endif{{ $issue['short_id'] }} · first seen @ago($issue['first_seen'], true)</span>
                            </span>
                            <span class="text-right font-semibold tabular-nums">{{ $num($issue['count']) }}</span>
                            <span class="text-right tabular-nums text-muted">{{ $num($issue['users']) }}</span>
                            <span class="text-[12.5px] text-muted" title="@datetime($issue['last_seen'])">@ago($issue['last_seen'], true)</span>
                        </button>

                        @if ($open === $issue['id'])
                            <div class="gt-detail">
                                <div wire:loading wire:target="toggleIssue" class="text-[13px] text-muted">Loading the latest event…</div>
                                @if ($eventError)
                                    <p class="text-[13px]" style="color: var(--mon-5xx)">{{ $eventError }}</p>
                                @elseif ($event)
                                    <div class="gt-message">
                                        <span class="font-mono text-[12px] text-muted">{{ $event['type'] }}</span>
                                        <p class="mt-1 text-[14px]">{{ $event['message'] }}</p>
                                        @if ($event['request'])
                                            <p class="mt-2 font-mono text-[12px] text-muted">{{ $event['request']['method'] }} {{ $event['request']['url'] }}</p>
                                        @endif
                                    </div>

                                    @if ($event['tags'] !== [])
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($event['tags'] as $key => $value)
                                                <span class="gt-tag"><b>{{ $key }}</b> {{ $value }}</span>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($event['frames'] !== [])
                                        <div class="gt-frames">
                                            <p class="mon-label">Stack trace · newest call first</p>
                                            @foreach ($event['frames'] as $index => $frame)
                                                <details class="gt-frame {{ $frame['in_app'] ? 'is-app' : '' }}" @if ($index === 0 || ($frame['in_app'] && $loop->first)) open @endif>
                                                    <summary>
                                                        <span class="truncate font-mono">{{ $frame['file'].($frame['line'] ? ':'.$frame['line'] : '') }}</span>
                                                        <span class="truncate text-muted">{{ $frame['function'] }}</span>
                                                        @if ($frame['in_app'])<span class="gt-tag">your code</span>@endif
                                                    </summary>
                                                    @if ($frame['context'] !== [])
                                                        <pre class="gt-code">@foreach ($frame['context'] as $line)<span class="{{ $line['number'] === $frame['line'] ? 'is-line' : '' }}"><i>{{ $line['number'] }}</i>{{ $line['code'] }}</span>@endforeach</pre>
                                                    @endif
                                                </details>
                                            @endforeach
                                        </div>
                                    @endif
                                @endif

                                <div class="flex flex-wrap gap-2">
                                    @if ($issue['status'] !== 'resolved')
                                        <button class="btn btn-primary" type="button" wire:click="setStatus('{{ $issue['id'] }}', 'resolved')">Resolve</button>
                                    @endif
                                    @if ($issue['status'] !== 'ignored')
                                        <button class="btn" type="button" wire:click="setStatus('{{ $issue['id'] }}', 'ignored')">Ignore</button>
                                    @endif
                                    @if ($issue['status'] !== 'unresolved')
                                        <button class="btn" type="button" wire:click="setStatus('{{ $issue['id'] }}', 'unresolved')">Open again</button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @elseif ($section === 'performance')
            <div class="gt-list" wire:loading.class="opacity-60" wire:target="project,period,query,sort,goTo">
                <div class="gt-head gt-perf-row">
                    <span>Transaction</span><span class="text-right">Requests</span><span class="text-right">Avg</span><span class="text-right">P50</span><span class="text-right">P95</span><span class="text-right">Errors</span>
                </div>
                @foreach ($rows as $group)
                    @php $slow = ($group['p95'] ?? 0) >= 1000; @endphp
                    <div wire:key="tx-{{ $group['id'] }}" class="gt-item">
                        <div class="gt-perf-row">
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    @if ($group['method'] !== '')<span class="gt-tag font-mono">{{ $group['method'] }}</span>@endif
                                    <span class="truncate font-mono text-[13px] font-semibold">{{ $group['transaction'] }}</span>
                                </span>
                                <span class="mt-0.5 block text-[11.5px] text-muted">@if ($lockedProject === null){{ $group['website'] }} · @endif{{ $group['op'] }}@if ($group['throughput'] !== null) · {{ round($group['throughput'], 2) }}/min @endif · last @ago($group['last_seen'], true)</span>
                            </span>
                            <span class="text-right tabular-nums">{{ $num($group['count']) }}</span>
                            <span class="text-right tabular-nums">{{ $ms($group['avg']) }}</span>
                            <span class="text-right tabular-nums text-muted">{{ $ms($group['p50']) }}</span>
                            <span class="text-right font-semibold tabular-nums" @style(['color: var(--mon-p95)' => $slow])>{{ $ms($group['p95']) }}</span>
                            <span class="text-right tabular-nums" @style(['color: var(--mon-5xx)' => $group['error_rate'] > 0])>{{ round($group['error_rate'] * ($group['error_rate'] <= 1 ? 100 : 1), 1) }}%</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            @php
                $levelCounts = collect($counts);
                $days = collect($rows)->groupBy(fn ($log) => \Illuminate\Support\Carbon::parse($log['time'])->setTimezone(config('app.timezone'))->toDateString());
                $dayLabel = fn (string $day) => match (true) {
                    $day === now(config('app.timezone'))->toDateString() => 'Today',
                    $day === now(config('app.timezone'))->subDay()->toDateString() => 'Yesterday',
                    default => \Illuminate\Support\Carbon::parse($day)->format('l, M j'),
                };
            @endphp

            <div class="gt-chips" role="group" aria-label="Log level">
                <button type="button" class="gt-chip {{ $level === '' ? 'is-on' : '' }}" wire:click="$set('level', '')">All <b>{{ $levelCounts->sum() }}</b></button>
                @foreach (['error' => 'Errors', 'warn' => 'Warnings', 'info' => 'Info', 'debug' => 'Debug'] as $value => $label)
                    <button type="button" class="gt-chip {{ $level === $value ? 'is-on' : '' }}" style="--level: {{ $levelColor($value) }}" wire:click="$set('level', '{{ $level === $value ? '' : $value }}')">
                        <i></i> {{ $label }} @if ($level === '' || $level === $value)<b>{{ (int) ($levelCounts[$value] ?? 0) + ($value === 'error' ? (int) ($levelCounts['fatal'] ?? 0) : 0) }}</b>@endif
                    </button>
                @endforeach
            </div>

            <div class="gt-list" wire:loading.class="opacity-60" wire:target="project,period,query,level,goTo">
                @foreach ($days as $day => $dayLogs)
                    <div class="gt-day">{{ $dayLabel($day) }}</div>
                    @foreach ($dayLogs as $log)
                        <details wire:key="log-{{ $log['id'] }}" class="gt-log" style="--level: {{ $levelColor($log['level']) }}">
                            <summary>
                                <time class="gt-log-time" title="@datetime($log['time'])">{{ \Illuminate\Support\Carbon::parse($log['time'])->setTimezone(config('app.timezone'))->format('H:i:s') }}</time>
                                <span class="gt-log-level">{{ $log['level'] === 'warn' ? 'warning' : $log['level'] }}</span>
                                <span class="gt-log-body">{{ $log['body'] }}</span>
                                @if ($lockedProject === null)
                                    <span class="gt-log-site">{{ $log['website'] }}</span>
                                @endif
                                <x-menu-icon name="chevron-down" class="gt-log-caret size-4" />
                            </summary>
                            <div class="gt-detail">
                                <pre class="gt-code whitespace-pre-wrap">{{ $log['body'] }}</pre>
                                <dl class="gt-facts">
                                    <div><dt>Time</dt><dd>@datetime($log['time'])</dd></div>
                                    @foreach (array_filter(['Service' => $log['service'], 'Environment' => $log['environment'], 'Trace' => $log['trace_id'], 'Website' => $lockedProject === null ? $log['website'] : null]) as $key => $value)
                                        <div><dt>{{ $key }}</dt><dd class="font-mono">{{ $value }}</dd></div>
                                    @endforeach
                                </dl>
                                @if ($log['data'] !== [])
                                    <div>
                                        <p class="mon-label mb-1.5">Attributes</p>
                                        <dl class="gt-facts">
                                            @foreach ($log['data'] as $key => $value)
                                                <div><dt class="font-mono">{{ $key }}</dt><dd class="font-mono">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</dd></div>
                                            @endforeach
                                        </dl>
                                    </div>
                                @endif
                            </div>
                        </details>
                    @endforeach
                @endforeach
            </div>
        @endif

        @if ($rows !== [] && ($page['next'] || $page['previous'] || $offset > 0))
            <div class="gt-pager">
                <span>{{ number_format($offset + 1).'–'.number_format($offset + count($rows)).($page['total'] !== null ? ' of '.number_format($page['total']) : '') }}</span>
                <button class="btn" type="button" wire:click="goTo(@js($page['previous']), -1)" @disabled(! $page['previous'] || $offset === 0) wire:loading.attr="disabled"><x-menu-icon name="chevron-right" class="rotate-180" /> Previous</button>
                <button class="btn" type="button" wire:click="goTo(@js($page['next']), 1)" @disabled(! $page['next']) wire:loading.attr="disabled">Next <x-menu-icon name="chevron-right" /></button>
            </div>
        @endif
    @endif
</div>
