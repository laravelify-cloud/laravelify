{{-- Search data, table names, or column names in some databases or all of them. Results link to the record. --}}
<x-layer-card title="Search" helper="Look through {{ isset($fixedScope) ? 'this database' : 'every database' }} for a value, or find a table or a column by its name.">
    <form class="grid gap-4" wire:submit="runSearch">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <label class="field min-w-0 flex-1">
                <span class="label">What to find</span>
                <input class="input" type="search" wire:model="query" placeholder="{{ $mode === 'data' ? 'A name, an email, or an id' : ($mode === 'table' ? 'A table name, like users' : 'A column name, like email') }}" aria-label="What to find" autofocus>
            </label>
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="runSearch">
                <span class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap" wire:loading.remove wire:target="runSearch"><x-menu-icon name="search" /> Search</span>
                <span class="whitespace-nowrap" wire:loading wire:target="runSearch">Searching…</span>
            </button>
        </div>

        <div class="grid items-end gap-3 md:grid-cols-3">
            <div class="field">
                <span class="label">Look in</span>
                <div class="segmented" role="group" aria-label="Look in">
                    <button type="button" wire:click="$set('mode', 'data')" aria-pressed="{{ $mode === 'data' ? 'true' : 'false' }}">Data</button>
                    <button type="button" wire:click="$set('mode', 'table')" aria-pressed="{{ $mode === 'table' ? 'true' : 'false' }}">Tables</button>
                    <button type="button" wire:click="$set('mode', 'column')" aria-pressed="{{ $mode === 'column' ? 'true' : 'false' }}">Columns</button>
                </div>
            </div>
            <label class="field min-w-0">
                <span class="label">Match</span>
                <select class="select" wire:model.live="match" aria-label="How it matches">
                    <option value="contains">Contains</option>
                    <option value="equals">Is exactly</option>
                    <option value="starts">Starts with</option>
                    <option value="regex">Matches a pattern</option>
                </select>
            </label>
            @if ($mode === 'data')
                <label class="field min-w-0">
                    <span class="label">Only columns named</span>
                    <input class="input" wire:model="column" placeholder="Optional. Blank searches every column." aria-label="Only columns named">
                </label>
            @endif
            @if (count($scopeChoices) > 1 && ! isset($fixedScope))
                <div class="field">
                    <span class="label">Databases</span>
                    <details class="relative">
                        <summary class="btn w-fit cursor-pointer">{{ $scope === [] ? 'All databases' : count($scope).' of '.count($scopeChoices).' databases' }}</summary>
                        <div class="absolute z-20 mt-1 max-h-72 w-64 overflow-auto rounded-lg border border-line bg-card p-2 shadow-lg">
                            @foreach ($scopeChoices as $choice)
                                <label class="flex items-center gap-2 rounded px-2 py-1.5 text-[13px]"><input type="checkbox" wire:model="scope" value="{{ $choice }}"> <span class="font-mono">{{ $choice }}</span></label>
                            @endforeach
                        </div>
                    </details>
                </div>
            @endif
        </div>

        @if ($match === 'regex')
            <p class="text-[12.5px] text-muted">A MySQL pattern. <span class="font-mono">^AZE[0-9]{7}$</span> matches a value that starts with AZE and then seven digits.</p>
        @endif

        @if ($searchError)
            <p class="error">{{ $searchError }}</p>
        @endif
    </form>

    @if ($found !== null)
        <div class="mt-4">
            <p class="mb-2 text-[12.5px] text-muted">
                {{ count($found['results']) }} {{ count($found['results']) === 1 ? 'result' : 'results' }}{{ $found['truncated'] ? ' (showing the first ones — narrow the search to see more)' : '' }}
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
