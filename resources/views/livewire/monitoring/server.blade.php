@php
    use App\Services\SiteMetrics;

    $bytes = function (float|int|null $value): string {
        if ($value === null) {
            return '—';
        }

        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $index => $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return ($index === 0 ? (int) $value : round($value, 1)).' '.$unit;
            }

            $value /= 1024;
        }
    };
    $percent = fn (?float $value): string => $value === null ? '—' : round($value, 1).'%';
    $uptime = function (?int $seconds): string {
        if ($seconds === null) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $days > 0 ? $days.'d '.$hours.'h' : ($hours > 0 ? $hours.'h '.$minutes.'m' : $minutes.'m');
    };
    $summary = $history['summary'];
    $memory = $now['memory'];
    $disk = $now['disk'];
    $load = $now['load'];
@endphp

<div class="flex flex-col gap-6" wire:poll.60s.visible>
    <x-page-header title="Server monitoring" summary="Live CPU, memory, load, and network every 2 seconds, history from the per-minute samples, services, and the busiest processes.">
        <x-slot:actions>
            <div class="segmented" role="group" aria-label="History range">
                @foreach (SiteMetrics::RANGES as $key => [$label])
                    <button type="button" wire:click="setRange('{{ $key }}')" aria-pressed="{{ $range === $key ? 'true' : 'false' }}" class="font-mono text-[11.5px] font-semibold">{{ $label }}</button>
                @endforeach
            </div>
        </x-slot:actions>
    </x-page-header>

    @unless ($now['available'])
        <div class="mon-card mon-empty">
            <x-menu-icon name="cpu" class="size-5" />
            This machine does not expose /proc, so there is nothing to measure. Server monitoring works on the Linux server Laravelify runs on.
        </div>
    @else
        <div class="mon-card flex flex-wrap items-center gap-x-8 gap-y-3">
            <div class="flex items-center gap-3">
                <div class="stat-icon"><x-menu-icon name="server" /></div>
                <div>
                    <p class="font-semibold">{{ $now['hostname'] ?? 'Server' }}</p>
                    <p class="text-[12px] text-muted">{{ $now['os'] ?? 'Linux' }} · kernel {{ $now['kernel'] }}</p>
                </div>
            </div>
            <div><p class="mon-label">Uptime</p><p class="mt-1 text-[14px] font-semibold">{{ $uptime($now['uptime']) }}</p></div>
            <div><p class="mon-label">CPU cores</p><p class="mt-1 text-[14px] font-semibold">{{ $now['cores'] }}</p></div>
            <div><p class="mon-label">Memory</p><p class="mt-1 text-[14px] font-semibold">{{ $memory ? $bytes($memory['total']) : '—' }}</p></div>
            <div><p class="mon-label">Disk /</p><p class="mt-1 text-[14px] font-semibold">{{ $disk ? $bytes($disk['used']).' of '.$bytes($disk['total']) : '—' }}</p></div>
        </div>

        {{-- Live: polls every 2 seconds on its own; Livewire never re-renders it. --}}
        <div class="flex flex-col gap-4" wire:ignore x-data="serverLive(@js(route('monitoring.server.live')))">
            <div class="flex items-center gap-2.5">
                <span class="mon-live" :class="failed && 'is-paused'" x-text="failed ? 'Reconnecting…' : 'Live'"></span>
                <span class="mon-label">Every 2 seconds · last 3 minutes</span>
            </div>

            <div class="mon-grid-4">
                <div class="mon-card">
                    <p class="mon-label">CPU</p>
                    <p class="mon-value tabular-nums" :style="current && tone(current.cpu)" x-text="current && current.cpu !== null ? current.cpu.toFixed(1) + '%' : '—'">—</p>
                    <p class="mon-sub" x-text="current && current.load ? 'load ' + current.load.map(v => v.toFixed(2)).join(' · ') : ' '"></p>
                    <svg class="mon-live-svg" viewBox="0 0 300 64" preserveAspectRatio="none" aria-hidden="true"><path class="mon-line" style="stroke: var(--mon-p95)" :d="path('cpu', 100)" /></svg>
                </div>
                <div class="mon-card">
                    <p class="mon-label">Memory</p>
                    <p class="mon-value tabular-nums" :style="current && tone(current.memory)" x-text="current && current.memory !== null ? current.memory.toFixed(1) + '%' : '—'">—</p>
                    <p class="mon-sub" x-text="current && current.memory_used ? monitorFormat.bytes(current.memory_used) + ' of ' + monitorFormat.bytes(current.memory_total) : ' '"></p>
                    <svg class="mon-live-svg" viewBox="0 0 300 64" preserveAspectRatio="none" aria-hidden="true"><path class="mon-line" style="stroke: var(--mon-p95)" :d="path('memory', 100)" /></svg>
                </div>
                <div class="mon-card">
                    <p class="mon-label">Load (1 min)</p>
                    <p class="mon-value tabular-nums" x-text="current && current.load ? current.load[0].toFixed(2) : '—'">—</p>
                    <p class="mon-sub">{{ $now['cores'] }} {{ $now['cores'] === 1 ? 'core' : 'cores' }}</p>
                    <svg class="mon-live-svg" viewBox="0 0 300 64" preserveAspectRatio="none" aria-hidden="true"><path class="mon-line" style="stroke: var(--mon-p95)" :d="path('load', Math.max({{ $now['cores'] }}, ...series('load').map(v => v || 0)) * 1.2)" /></svg>
                </div>
                <div class="mon-card">
                    <p class="mon-label">Network</p>
                    <p class="mon-value mon-value-sm tabular-nums"><span class="text-[var(--mon-up)]">↓</span> <span x-text="current ? monitorFormat.bytes(current.net_in) + '/s' : '—'">—</span></p>
                    <p class="mon-value mon-value-sm !mt-0.5 tabular-nums"><span class="text-[var(--mon-p95)]">↑</span> <span x-text="current ? monitorFormat.bytes(current.net_out) + '/s' : '—'">—</span></p>
                    <svg class="mon-live-svg" viewBox="0 0 300 64" preserveAspectRatio="none" aria-hidden="true">
                        <path class="mon-line" style="stroke: var(--mon-up)" :d="path('in', netMax())" />
                        <path class="mon-line" style="stroke: var(--mon-p95)" :d="path('out', netMax())" />
                    </svg>
                </div>
            </div>

            <x-layer-card title="Top processes" helper="By CPU, refreshed every 6 seconds." flush>
                <div class="overflow-x-auto">
                    <table class="table mon-table">
                        <thead><tr><th>Process</th><th>User</th><th class="w-48">CPU</th><th class="text-right">Memory</th></tr></thead>
                        <tbody>
                            <template x-for="process in processes" :key="process.pid">
                                <tr>
                                    <td><span class="mono" x-text="process.name"></span> <span class="text-[11px] text-muted" x-text="'#' + process.pid"></span></td>
                                    <td class="text-muted" x-text="process.user"></td>
                                    <td>
                                        <span class="flex items-center gap-2">
                                            <span class="mon-meter flex-1"><span :style="'width:' + Math.min(100, process.cpu) + '%'"></span></span>
                                            <span class="w-12 text-right tabular-nums" x-text="process.cpu.toFixed(1) + '%'"></span>
                                        </span>
                                    </td>
                                    <td class="text-right tabular-nums" x-text="process.memory.toFixed(1) + '%'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p class="mon-empty" x-show="processes.length === 0">Loading processes…</p>
                </div>
            </x-layer-card>
        </div>

        <div class="flex items-center gap-2.5">
            <div class="stat-icon size-8"><x-menu-icon name="chart" /></div>
            <h3 class="mon-label text-[12px] text-ink">History</h3>
            <span class="text-[12px] text-muted">{{ $summary['samples'] }} samples · one per minute</span>
        </div>

        @if ($summary['samples'] === 0)
            <div class="mon-card mon-empty">
                <x-menu-icon name="chart" class="size-5" />
                No samples yet. The scheduler takes one every minute; the history fills in as it runs.
            </div>
        @else
            <div class="mon-grid-2">
                <div class="mon-card">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="mon-label">CPU</p><p class="mon-value">{{ $summary['cpu_avg'] !== null ? $summary['cpu_avg'].'%' : '—' }}</p></div>
                        <div class="mon-legend"><div class="mon-legend-item"><p class="mon-label">Peak</p><p>{{ $percent($summary['cpu_max']) }}</p></div></div>
                    </div>
                    <x-monitor.multi :buckets="$history['buckets']" :series="[['key' => 'cpu', 'label' => 'CPU', 'color' => 'var(--mon-p95)']]" format="percent" :max="100" />
                </div>
                <div class="mon-card">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="mon-label">Memory</p><p class="mon-value">{{ $summary['memory_avg'] !== null ? $summary['memory_avg'].'%' : '—' }}</p></div>
                        <div class="mon-legend"><div class="mon-legend-item"><p class="mon-label">Peak</p><p>{{ $percent($summary['memory_max']) }}</p></div></div>
                    </div>
                    <x-monitor.multi :buckets="$history['buckets']" :series="[['key' => 'memory', 'label' => 'Memory', 'color' => 'var(--mon-p95)'], ['key' => 'swap', 'label' => 'Swap', 'color' => 'var(--mon-avg)']]" format="percent" :max="100" />
                </div>
                <div class="mon-card">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="mon-label">Load average</p><p class="mon-value">{{ $summary['load_max'] !== null ? number_format($summary['load_max'], 2) : '—' }}</p></div>
                        <div class="mon-legend"><div class="mon-legend-item"><p class="mon-label">Cores</p><p>{{ $now['cores'] }}</p></div></div>
                    </div>
                    <x-monitor.multi :buckets="$history['buckets']" :series="[['key' => 'load1', 'label' => 'Load', 'color' => 'var(--mon-p95)']]" />
                </div>
                <div class="mon-card">
                    <div class="flex items-start justify-between gap-4">
                        <div><p class="mon-label">Network</p></div>
                        <div class="mon-legend">
                            <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot is-up"></span>In</p></div>
                            <div class="mon-legend-item"><p class="mon-label"><span class="mon-dot is-p95"></span>Out</p></div>
                        </div>
                    </div>
                    <x-monitor.multi :buckets="$history['buckets']" :series="[['key' => 'net_in', 'label' => 'In', 'color' => 'var(--mon-up)'], ['key' => 'net_out', 'label' => 'Out', 'color' => 'var(--mon-p95)']]" format="bytes" />
                </div>
            </div>
        @endif

        <x-layer-card title="Services" helper="systemd status of what Laravelify runs." flush>
            <div class="grid md:grid-cols-2">
                @forelse ($services as $service)
                    <div class="mon-issue items-center" wire:key="service-{{ $service['unit'] }}">
                        <span class="status-badge w-24 justify-center" style="--status-color: {{ $service['state'] === 'running' ? 'var(--mon-up)' : 'var(--mon-5xx)' }}">{{ ucfirst($service['state']) }}</span>
                        <span class="flex-1 text-[13.5px] font-medium">{{ $service['name'] }}</span>
                        <code class="text-[11.5px] text-muted">{{ $service['unit'] }}</code>
                    </div>
                @empty
                    <div class="mon-empty md:col-span-2">Service status needs systemd. Nothing to show here.</div>
                @endforelse
            </div>
        </x-layer-card>
    @endunless
</div>
