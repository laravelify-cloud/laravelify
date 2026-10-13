@if ($rows->isEmpty())
    <div class="mon-empty"><x-menu-icon name="search" class="size-5" />No matching requests in this range.</div>
@else
    <div class="overflow-x-auto">
        <table class="table mon-table">
            <thead>
                <tr><th>Time</th><th>Request</th><th>Status</th><th class="text-right">Duration</th><th>IP</th>@if ($showThreat)<th>Threat</th>@endif</tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr wire:key="request-{{ $row->id }}">
                        <td class="whitespace-nowrap text-[12px] text-muted">@datetime($row->at)</td>
                        <td class="min-w-[240px]">
                            <span class="flex items-center gap-2">
                                <span class="mon-code">{{ $row->method }}</span>
                                <span class="mon-path" title="{{ $row->uri }}">{{ $row->uri }}</span>
                            </span>
                        </td>
                        <td><span class="mon-code {{ $codeClass($row->status) }}">{{ $row->status }}</span></td>
                        <td class="text-right tabular-nums {{ $row->duration_ms >= $website->monitor_slow_ms ? 'text-[var(--mon-p95)]' : '' }}">{{ $ms($row->duration_ms) }}</td>
                        <td class="mono whitespace-nowrap" title="{{ $row->user_agent }}">{{ $row->ip }}@if ($row->country) <span class="text-muted">· {{ $row->country }}</span>@endif</td>
                        @if ($showThreat)
                            <td class="whitespace-nowrap">
                                @if ($row->risk > 0)
                                    <span class="mon-risk is-{{ \App\Services\ThreatDetector::level($row->risk) }}" title="{{ \App\Services\ThreatDetector::label($row->threat) }}">{{ \Illuminate\Support\Str::limit(\App\Services\ThreatDetector::label($row->threat), 22) }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if ($rows->hasPages())
        <div class="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5 text-[12.5px] text-muted">
            <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }} · {{ number_format($rows->total()) }} requests</span>
            <div class="flex gap-1.5">
                <button class="btn" type="button" wire:click="previousPage('requests')" @disabled($rows->onFirstPage())>Previous</button>
                <button class="btn" type="button" wire:click="nextPage('requests')" @disabled(! $rows->hasMorePages())>Next</button>
            </div>
        </div>
    @endif
@endif
