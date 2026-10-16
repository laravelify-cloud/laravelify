{{-- Search data, table names, or column names in some databases or all of them. Results link to the record. --}}
<x-layer-card title="Search" helper="Find a value in every table (like a passport number), or tables and columns by name. Regex uses MySQL's syntax, like ^AZE[0-9]{7}$.">
    <form class="grid gap-3" wire:submit="runSearch">
        <div class="flex flex-wrap gap-2">
            <input class="input min-w-0 flex-1 font-mono" type="search" wire:model="query" placeholder="{{ $mode === 'data' ? 'AZE1234567' : ($mode === 'table' ? 'users' : 'firstname') }}" aria-label="Look for" autofocus>
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="runSearch">
                <span wire:loading.remove wire:target="runSearch"><x-menu-icon name="search" class="size-4" /> Search</span>
                <span wire:loading wire:target="runSearch">Searching…</span>
            </button>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="segmented" role="group" aria-label="Look in">
                <button type="button" wire:click="$set('mode', 'data')" aria-pressed="{{ $mode === 'data' ? 'true' : 'false' }}" class="text-[12px]">Data</button>
                <button type="button" wire:click="$set('mode', 'table')" aria-pressed="{{ $mode === 'table' ? 'true' : 'false' }}" class="text-[12px]">Table names</button>
                <button type="button" wire:click="$set('mode', 'column')" aria-pressed="{{ $mode === 'column' ? 'true' : 'false' }}" class="text-[12px]">Column names</button>
            </div>
            <select class="input h-8 w-auto" wire:model="match" data-native aria-label="How it matches">
                <option value="contains">Contains</option>
                <option value="equals">Is exactly</option>
                <option value="starts">Starts with</option>
                <option value="regex">Regex</option>
            </select>
            @if ($mode === 'data')
                <input class="input h-8 w-56 font-mono text-[12.5px]" wire:model="column" placeholder="Only columns named like… (firstname)" aria-label="Only in columns named like">
            @endif
            @if (count($scopeChoices) > 1 && ! isset($fixedScope))
                <details class="relative">
                    <summary class="btn btn-sm cursor-pointer">{{ $scope === [] ? 'All databases' : count($scope).' of '.count($scopeChoices).' databases' }}</summary>
                    <div class="absolute z-20 mt-1 max-h-72 w-64 overflow-auto rounded-lg border border-line bg-recessed p-2 shadow-lg">
                        @foreach ($scopeChoices as $choice)
                            <label class="flex items-center gap-2 rounded px-2 py-1 text-[13px]"><input type="checkbox" wire:model="scope" value="{{ $choice }}"> <span class="font-mono">{{ $choice }}</span></label>
                        @endforeach
                    </div>
                </details>
            @endif
        </div>
        @if ($searchError)
            <p class="error">{{ $searchError }}</p>
        @endif
    </form>

    @if ($found !== null)
        <div class="mt-4">
            <p class="mb-2 text-[12.5px] text-muted">
                {{ count($found['results']) }} {{ count($found['results']) === 1 ? 'result' : 'results' }}{{ $found['truncated'] ? ' (the first ones; narrow the search for more)' : '' }}
                @if ($mode === 'data') · {{ $found['searched'] }} tables searched @endif
                @if ($found['skipped'] !== []) · skipped: {{ implode(', ', array_slice($found['skipped'], 0, 8)) }}{{ count($found['skipped']) > 8 ? '…' : '' }} @endif
            </p>
            <div class="overflow-hidden rounded-lg border border-line">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Where</th>
                            <th>{{ $mode === 'data' ? 'Found' : ($mode === 'column' ? 'Column' : 'Rows · size') }}</th>
                            <th class="w-28"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($found['results'] as $index => $result)
                            @php($link = route('database.table', [$result['database'], $result['table']]).(! empty($result['keys']) ? '?row='.urlencode(json_encode($result['keys'])) : ''))
                            <tr wire:key="result-{{ $index }}">
                                <td class="font-mono text-[12.5px]">
                                    <span class="text-muted">{{ $result['database'] }}.</span>{{ $result['table'] }}
                                    @if (! empty($result['keys']))
                                        <span class="block text-[11.5px] text-muted">{{ collect($result['keys'])->map(fn ($value, $column) => $column.' = '.$value)->implode(', ') }}</span>
                                    @endif
                                </td>
                                <td class="max-w-lg text-[12.5px]">
                                    @if ($mode === 'data')
                                        @foreach ($result['found'] as $column => $value)
                                            <span class="block truncate"><span class="font-mono text-muted">{{ $column }}:</span> {{ $value }}</span>
                                        @endforeach
                                    @elseif ($mode === 'column')
                                        <span class="font-mono">{{ $result['column'] }}</span> <span class="text-muted">{{ $result['type'] }}</span>
                                    @else
                                        <span class="text-muted">{{ number_format($result['rows'] ?? 0) }} rows · {{ \Illuminate\Support\Number::fileSize($result['size'] ?? 0, precision: 1) }}</span>
                                    @endif
                                </td>
                                <td><a class="btn btn-sm" href="{{ $link }}" wire:navigate>{{ ! empty($result['keys']) ? 'Open record' : 'Open table' }}</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="table-empty">Nothing found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-layer-card>
