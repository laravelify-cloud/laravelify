{{-- The History tab: every SQL run in this database (KeepsSqlHistory). $historyChoices (optional): databases to pick from. --}}
@php
    [, $historyDatabase] = $this->historyTarget();
    $history = $this->sqlHistory;
    $counts = $this->sqlHistoryCounts;
    $more = $history->count() > $historyShown;
    $history = $history->take($historyShown);
    $days = $history->groupBy(fn ($entry) => $entry->created_at?->isToday() ? 'Today' : ($entry->created_at?->isYesterday() ? 'Yesterday' : $entry->created_at?->format('l, j F Y')));
@endphp
<div class="sqlh">
    <div class="sqlh-bar">
        @isset($historyChoices)
            <select class="select h-9 w-auto font-mono text-[12.5px]" wire:model.live="sqlIn" aria-label="Database">
                <option value="">Choose a database</option>
                @foreach ($historyChoices as $choice)
                    <option value="{{ $choice }}">{{ $choice }}</option>
                @endforeach
            </select>
        @endisset
        <div class="sqlh-filters" role="group" aria-label="Show">
            @foreach (['' => 'All', 'read' => 'Read', 'write' => 'Changed', 'error' => 'Failed'] as $value => $label)
                <button type="button" wire:click="$set('historyKind', '{{ $value }}')" aria-pressed="{{ $historyKind === $value ? 'true' : 'false' }}" data-kind="{{ $value }}">
                    {{ $label }} <span>{{ number_format($counts[$value] ?? 0) }}</span>
                </button>
            @endforeach
        </div>
        <div class="sqlh-search">
            <x-menu-icon name="search" class="size-4" />
            <input type="search" wire:model.live.debounce.300ms="historySearch" placeholder="Search the SQL" aria-label="Search the SQL">
        </div>
        @if (($counts[''] ?? 0) > 0)
            <button class="btn btn-sm sqlh-clear" type="button" wire:click="clearHistory" wire:confirm="Clear the whole SQL history of {{ $historyDatabase }}? The data itself is not touched."><x-menu-icon name="trash" class="size-4" /> Clear history</button>
        @endif
    </div>

    @if ($historyDatabase === '')
        <div class="sqlh-empty"><x-menu-icon name="clock" class="size-6" /><p>Choose a database to see its SQL history.</p></div>
    @elseif ($history->isEmpty())
        <div class="sqlh-empty">
            <x-menu-icon name="clock" class="size-6" />
            <p>{{ $historySearch !== '' || $historyKind !== '' ? 'Nothing in the history matches.' : 'Nothing run in '.$historyDatabase.' yet.' }}</p>
            @if ($historySearch === '' && $historyKind === '')
                <span>Every query from the SQL tab shows up here, with the rows an UPDATE or DELETE changed.</span>
            @endif
        </div>
    @else
        @foreach ($days as $day => $entries)
            <p class="sqlh-day">{{ $day }}</p>
            <div class="sqlh-list">
                @foreach ($entries as $entry)
                    @php $open = $historyOpen === $entry->id; @endphp
                    <div class="sqlh-item {{ $open ? 'is-open' : '' }}" data-kind="{{ $entry->kind }}" wire:key="history-{{ $entry->id }}">
                        <div class="sqlh-main">
                            <span class="sqlh-icon" title="{{ ['read' => 'Read', 'write' => 'Changed rows', 'error' => 'Failed'][$entry->kind] ?? 'Ran' }}">
                                <x-menu-icon :name="['read' => 'eye', 'write' => 'pencil', 'error' => 'danger'][$entry->kind] ?? 'play'" class="size-4" />
                            </span>
                            <div class="sqlh-body">
                                <pre class="sqlh-sql" title="{{ \Illuminate\Support\Str::limit($entry->sql, 3000) }}">{{ \Illuminate\Support\Str::limit($entry->sql, 600) }}</pre>
                                <p class="sqlh-meta">
                                    <span class="sqlh-result">
                                        @if (! $entry->ok)
                                            {{ \Illuminate\Support\Str::limit((string) $entry->error, 200) }}
                                        @elseif ($entry->kind === 'write')
                                            {{ number_format((int) $entry->affected).' '.((int) $entry->affected === 1 ? 'row changed' : 'rows changed') }}
                                        @else
                                            {{ number_format((int) $entry->rows).' '.((int) $entry->rows === 1 ? 'row' : 'rows') }}
                                        @endif
                                    </span>
                                    <span title="{{ $entry->created_at }}">{{ $entry->created_at?->format('H:i:s') }}</span>
                                    <span>{{ $entry->duration_ms }} ms</span>
                                    @if ($entry->user)
                                        <span>{{ $entry->user->name }}</span>
                                    @endif
                                </p>
                            </div>
                            <div class="sqlh-actions">
                                @if ($entry->changes)
                                    <button class="btn btn-sm {{ $open ? 'is-on' : '' }}" type="button" wire:click="toggleHistoryChanges({{ $entry->id }})"><x-menu-icon name="table" class="size-4" /> {{ $open ? 'Hide rows' : 'Changed rows' }}</button>
                                @endif
                                <button class="icon-btn" type="button" wire:click="useHistory({{ $entry->id }})" title="Open in the SQL tab" aria-label="Open in the SQL tab"><x-menu-icon name="pencil" /></button>
                                <button class="icon-btn" type="button" title="Copy" aria-label="Copy" x-data @click="navigator.clipboard.writeText(@js($entry->sql)); $el.classList.add('is-done'); setTimeout(() => $el.classList.remove('is-done'), 1200)"><x-menu-icon name="copy" /></button>
                                <button class="icon-btn" type="button" wire:click="deleteHistory({{ $entry->id }})" title="Remove from the history" aria-label="Remove from the history"><x-menu-icon name="trash" /></button>
                                <button class="btn btn-sm btn-primary" type="button" wire:click="useHistory({{ $entry->id }}, true)" @if ($entry->kind === 'write') wire:confirm="Run this again? It changed rows last time and will change them again." @endif><x-menu-icon name="play" class="size-3.5" /> Run</button>
                            </div>
                        </div>

                        @if ($open && $entry->changes)
                            <div class="sqlh-changes">
                                @foreach ($entry->changes as $change)
                                    @php
                                        $keys = $change['keys'] ?? [];
                                        $keyOf = fn (array $row): string => json_encode(array_map(fn ($key) => $row[$key] ?? null, $keys));
                                        $afterByKey = collect($change['after'] ?? [])->keyBy($keyOf);
                                        $isUpdate = $change['action'] === 'update';
                                        $columns = array_keys($change['before'][0] ?? []);

                                        if ($isUpdate && $afterByKey->isNotEmpty()) {
                                            $changed = array_values(array_filter($columns, fn ($column) => collect($change['before'])->contains(fn ($row) => ($afterByKey[$keyOf($row)][$column] ?? null) !== ($row[$column] ?? null))));
                                            $columns = array_values(array_unique([...$keys, ...$changed]));
                                        }
                                    @endphp
                                    <p class="sqlh-change-head">
                                        <span class="sqlh-tag" data-action="{{ $change['action'] }}">{{ $isUpdate ? 'Updated' : 'Deleted' }}</span>
                                        <span class="font-mono font-semibold">{{ $change['table'] }}</span>
                                        <span class="text-muted">{{ count($change['before']) }}{{ $change['truncated'] ? '+' : '' }} {{ count($change['before']) === 1 ? 'row' : 'rows' }}{{ $change['truncated'] ? ', the first '.\App\Services\SqlChangeCapture::MAX_ROWS.' kept' : '' }}{{ $isUpdate && $keys === [] ? ' · no key, so only the rows before are kept' : '' }}{{ $isUpdate && $afterByKey->isNotEmpty() && count($columns) === count($keys) ? ' · no value changed' : '' }}</span>
                                    </p>
                                    @if ($change['before'] === [])
                                        <p class="text-[12.5px] text-muted">No rows matched.</p>
                                    @else
                                        <div class="sqlh-rows">
                                            <table class="sql-sheet">
                                                <thead><tr>@foreach ($columns as $column)<th>{{ $column }}@if (in_array($column, $keys, true)) <span class="sqlh-key">key</span>@endif</th>@endforeach</tr></thead>
                                                <tbody>
                                                    @foreach ($change['before'] as $row)
                                                        @php $after = $isUpdate ? ($afterByKey[$keyOf($row)] ?? null) : null; @endphp
                                                        <tr>
                                                            @foreach ($columns as $column)
                                                                @php
                                                                    $old = $row[$column] ?? null;
                                                                    $new = $after[$column] ?? null;
                                                                    $differs = $after !== null && $old !== $new;
                                                                @endphp
                                                                <td class="{{ $differs ? 'is-changed' : (! $isUpdate ? 'is-deleted' : '') }}">
                                                                    @if ($differs)
                                                                        <span class="sql-old">{{ $old === null ? 'NULL' : \Illuminate\Support\Str::limit((string) $old, 80) }}</span><span class="sql-arrow">→</span><span class="sql-new">{{ $new === null ? 'NULL' : \Illuminate\Support\Str::limit((string) $new, 80) }}</span>
                                                                    @else
                                                                        {{ $old === null ? 'NULL' : \Illuminate\Support\Str::limit((string) $old, 80) }}
                                                                    @endif
                                                                </td>
                                                            @endforeach
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
        @if ($more)
            <div class="flex justify-center pt-1"><button class="btn btn-sm" type="button" wire:click="showMoreHistory">Show older runs</button></div>
        @endif
    @endif
</div>
