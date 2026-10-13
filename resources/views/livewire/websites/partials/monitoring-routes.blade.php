@if ($routes->isEmpty())
    <div class="mon-empty"><x-menu-icon name="chart" class="size-5" />No requests in this range yet.</div>
@else
    @php $topHits = max(1, (int) $routes->max('hits')); @endphp
    <div class="overflow-x-auto">
        <table class="table mon-table">
            <thead>
                <tr><th>Route</th><th class="text-right">Requests</th><th class="text-right">4xx</th><th class="text-right">5xx</th><th class="text-right">Avg</th><th class="text-right">Max</th></tr>
            </thead>
            <tbody>
                @foreach ($routes as $route)
                    <tr wire:key="route-{{ md5($route->method.$route->route) }}">
                        <td class="min-w-[220px]">
                            <span class="flex items-center gap-2">
                                <span class="mon-code">{{ $route->method }}</span>
                                <span class="mon-path" title="{{ $route->route }}">{{ $route->route }}</span>
                            </span>
                            <span class="mon-meter mt-1.5 block max-w-[260px]"><span style="width: {{ round($route->hits / $topHits * 100) }}%"></span></span>
                        </td>
                        <td class="text-right tabular-nums">{{ number_format($route->hits) }}</td>
                        <td class="text-right tabular-nums {{ $route->client_errors > 0 ? 'text-[var(--mon-4xx)]' : 'text-muted' }}">{{ number_format($route->client_errors) }}</td>
                        <td class="text-right tabular-nums {{ $route->server_errors > 0 ? 'text-[var(--mon-5xx)]' : 'text-muted' }}">{{ number_format($route->server_errors) }}</td>
                        <td class="text-right tabular-nums">{{ $ms((int) round($route->avg_ms)) }}</td>
                        <td class="text-right tabular-nums text-muted">{{ $ms((int) $route->max_ms) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
