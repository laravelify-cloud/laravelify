@php
    $canChange = $keyColumns !== [];
    $keyOf = fn (array $row) => collect($keyColumns)->mapWithKeys(fn ($column) => [$column => $row[$column] ?? null])->all();
    $cell = function ($value) {
        if ($value === null) {
            return null;
        }

        return \Illuminate\Support\Str::limit((string) $value, 80);
    };
@endphp
<div class="flex flex-col gap-6" x-data="{ selected: [] }" @rows-deleted.window="selected = []">
    <div class="page-header">
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
                <a class="transition-colors hover:text-ink" href="{{ route('database.index') }}" wire:navigate>Database</a>
                <x-menu-icon name="chevron-right" class="size-3.5 opacity-60" />
                <a class="font-mono transition-colors hover:text-ink" href="{{ route('database.show', $database) }}" wire:navigate>{{ $database }}</a>
            </p>
            <h2 class="page-title mt-1 flex items-center gap-2 truncate font-mono"><x-menu-icon name="table" class="size-5 text-muted" /> {{ $table }}</h2>
            <p class="page-summary">{{ number_format($data['total']) }} {{ $data['total'] === 1 ? 'row' : 'rows' }}{{ $search !== '' || $filters !== [] ? ' match' : '' }}{{ $canChange ? ' · key: '.implode(', ', $keyColumns) : ' · no primary or unique key, so rows are read-only here' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <button class="btn" type="button" wire:click="openExport"><x-menu-icon name="download" /> Export SQL</button>
            @if ($canChange)
                <button class="btn btn-primary" type="button" wire:click="newRow"><x-menu-icon name="plus" /> Add row</button>
            @endif
        </div>
    </div>

    @include('livewire.database.partials.export')

    <div class="segmented self-start" role="tablist">
        <button type="button" wire:click="$set('tab', 'browse')" aria-pressed="{{ $tab === 'browse' ? 'true' : 'false' }}">Rows</button>
        <button type="button" wire:click="$set('tab', 'structure')" aria-pressed="{{ $tab === 'structure' ? 'true' : 'false' }}">Structure</button>
        <button type="button" wire:click="$set('tab', 'sql')" aria-pressed="{{ $tab === 'sql' ? 'true' : 'false' }}">SQL</button>
    </div>

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $error }}</p></div>
    @endif

    @if ($tab === 'sql')
        @include('livewire.database.partials.sql', ['sqlTarget' => $database])
    @elseif ($tab === 'structure')
        <x-layer-card title="Columns" flush>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
                    <tbody>
                        @foreach ($columns as $column)
                            <tr wire:key="col-{{ $column['name'] }}">
                                <td class="font-mono text-[13px] font-medium">{{ $column['name'] }}</td>
                                <td class="font-mono text-[12.5px] text-muted">{{ $column['type'] }}</td>
                                <td class="text-muted">{{ $column['nullable'] ? 'yes' : 'no' }}</td>
                                <td class="text-muted">{{ $column['key'] }}</td>
                                <td class="font-mono text-[12.5px] text-muted">{{ $column['default'] ?? ($column['nullable'] ? 'NULL' : '—') }}</td>
                                <td class="text-muted">{{ $column['extra'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-layer-card>
    @else
        <x-layer-card title="Rows" helper="Click a row to see all of it. Click a column name to sort." flush>
            <x-slot:actions>
                <input class="input h-8 w-56" type="search" wire:model.live.debounce.400ms="search" placeholder="Search every column" aria-label="Search every column">
                <button class="btn btn-sm" type="button" wire:click="addFilter"><x-menu-icon name="plus" class="size-4" /> Filter</button>
                <select class="select h-8 w-auto" wire:model.live="perPage" aria-label="Rows per page">
                    @foreach ([25, 50, 100, 250, 500] as $count)
                        <option value="{{ $count }}">{{ $count }} per page</option>
                    @endforeach
                </select>
            </x-slot:actions>

            @if ($filters !== [])
                <div class="flex flex-col gap-3 border-b border-line p-3">
                    @foreach ($filters as $index => $filter)
                        <div class="flex flex-wrap items-end gap-2" wire:key="filter-{{ $index }}">
                            <label class="field w-56">
                                <span class="label">Column</span>
                                <select class="select font-mono text-[12.5px]" wire:model.live="filters.{{ $index }}.column" aria-label="Column">
                                    <option value="">Choose a column</option>
                                    @foreach ($columns as $column)
                                        <option value="{{ $column['name'] }}">{{ $column['name'] }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="field w-48">
                                <span class="label">Match</span>
                                <select class="select" wire:model.live="filters.{{ $index }}.operator" aria-label="How it matches">
                                    @foreach ($operators as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @unless (in_array($filter['operator'] ?? '', ['null', 'not_null'], true))
                                <label class="field min-w-0 flex-1">
                                    <span class="label">Value</span>
                                    <input class="input font-mono text-[12.5px]" wire:model.live.debounce.400ms="filters.{{ $index }}.value" placeholder="What to match" aria-label="Value">
                                </label>
                            @else
                                <span></span>
                            @endunless
                            <button class="icon-btn" type="button" wire:click="removeFilter({{ $index }})" aria-label="Remove filter"><x-menu-icon name="close" class="size-4" /></button>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="fm-selection" x-cloak x-show="selected.length > 0">
                <div class="flex items-center gap-2">
                    <button class="icon-btn" type="button" @click="selected = []" aria-label="Clear selection"><x-menu-icon name="close" class="size-4" /></button>
                    <span class="font-medium"><span x-text="selected.length"></span> selected</span>
                </div>
                <button class="btn btn-sm btn-danger" type="button" @click="$wire.askDelete(selected.map((key) => JSON.parse(key)))"><x-menu-icon name="trash" /> Delete</button>
            </div>

            @php
                $relatedLink = fn (array $to, $value) => route('database.table', [$to['database'], $to['table']]).'?'.http_build_query(['where' => [$to['column'] => (string) $value]]);
            @endphp
            <div class="db-grid-wrap">
                <table class="db-grid">
                    <thead>
                        <tr>
                            <th class="db-grid-num">#</th>
                            @if ($canChange)
                                <th class="db-grid-check"></th>
                            @endif
                            @foreach ($columns as $column)
                                @php
                                    $out = $relations['out'][$column['name']] ?? null;
                                    $in = $relations['in'][$column['name']] ?? [];
                                @endphp
                                <th title="{{ $column['type'] }}{{ $out ? ' · points at '.$out['table'].'.'.$out['column'] : '' }}{{ $in !== [] ? ' · used by '.collect($in)->map(fn ($r) => $r['table'].'.'.$r['column'])->implode(', ') : '' }}">
                                    <button class="db-grid-head" type="button" wire:click="sortBy('{{ $column['name'] }}')">
                                        <span class="truncate">{{ $column['name'] }}</span>
                                        @if (in_array($column['name'], $keyColumns, true)) <span class="db-grid-badge is-key">key</span> @endif
                                        @if ($out) <span class="db-grid-badge is-link">→ {{ $out['table'] }}</span> @endif
                                        @if ($in !== []) <span class="db-grid-badge is-link">← {{ count($in) }}</span> @endif
                                        <span class="ml-auto text-[11px] {{ $sort === $column['name'] ? 'text-accent' : 'opacity-40' }}">{{ $sort === $column['name'] ? ($direction === 'asc' ? '↑' : '↓') : '↕' }}</span>
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['rows'] as $index => $row)
                            @php $key = json_encode($keyOf($row)); @endphp
                            <tr wire:key="row-{{ $data['page'] }}-{{ $index }}-{{ md5($key) }}" class="{{ $canChange ? 'cursor-pointer' : '' }}" :class="selected.includes(@js($key)) && 'is-selected'" @if ($canChange) wire:click="openRow(@js($keyOf($row)))" @endif>
                                <td class="db-grid-num">{{ ($data['page'] - 1) * $data['per_page'] + $index + 1 }}</td>
                                @if ($canChange)
                                    <td class="db-grid-check" @click.stop>
                                        <input type="checkbox" aria-label="Select row" :checked="selected.includes(@js($key))" @change="selected = $event.target.checked ? [...selected, @js($key)] : selected.filter((item) => item !== @js($key))">
                                    </td>
                                @endif
                                @foreach ($columns as $column)
                                    @php
                                        $raw = $row[$column['name']] ?? null;
                                        $value = $cell($raw);
                                        $out = $relations['out'][$column['name']] ?? null;
                                        $in = $relations['in'][$column['name']] ?? [];
                                    @endphp
                                    <td class="{{ is_numeric($raw) ? 'is-number' : '' }}">
                                        @if ($value === null)
                                            <span class="db-grid-null">NULL</span>
                                        @elseif ($out)
                                            <a class="db-grid-link" href="{{ $relatedLink($out, $raw) }}" wire:navigate @click.stop title="Open the {{ $out['table'] }} row with {{ $out['column'] }} = {{ $value }}">{{ $value }}</a>
                                        @elseif ($in !== [])
                                            <span class="db-grid-related" x-data="{ open: false, x: 0, y: 0 }" @click.stop>
                                                <button class="db-grid-link" type="button" title="Rows in other tables that point at this one"
                                                    @click="const box = $el.getBoundingClientRect(); x = Math.min(box.left, window.innerWidth - 300); y = box.bottom + 4; open = ! open">{{ $value }}</button>
                                                <template x-teleport="body">
                                                    <div class="db-rel-menu" x-show="open" x-cloak @click.outside="open = false" @keydown.escape.window="open = false" :style="`left: ${x}px; top: ${y}px`">
                                                        <p class="db-rel-title">Rows that point at {{ $column['name'] }} = {{ $value }}</p>
                                                        @foreach ($in as $relation)
                                                            <a class="db-rel-item" href="{{ $relatedLink($relation, $raw) }}" wire:navigate>
                                                                <x-menu-icon name="table" class="size-4 shrink-0 text-muted" />
                                                                <span class="font-mono">{{ $relation['database'] !== $database ? $relation['database'].'.' : '' }}{{ $relation['table'] }}</span>
                                                                <span class="font-mono text-muted">({{ $relation['column'] }})</span>
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                </template>
                                            </span>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($columns) + 2 }}" class="table-empty">{{ $search !== '' || $filters !== [] ? 'No rows match.' : 'This table is empty.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($data['pages'] > 1)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-4 py-2.5 text-[12.5px] text-muted">
                    <span>Page {{ $data['page'] }} of {{ number_format($data['pages']) }} · rows {{ number_format(($data['page'] - 1) * $data['per_page'] + 1) }}–{{ number_format(min($data['total'], $data['page'] * $data['per_page'])) }} of {{ number_format($data['total']) }}</span>
                    <div class="flex items-center gap-1">
                        <button class="btn btn-sm" type="button" wire:click="goTo(1)" @disabled($data['page'] <= 1)>First</button>
                        <button class="btn btn-sm" type="button" wire:click="goTo({{ $data['page'] - 1 }})" @disabled($data['page'] <= 1)>Previous</button>
                        <input class="input h-8 w-20 text-center" type="number" min="1" max="{{ $data['pages'] }}" value="{{ $data['page'] }}" @change="$wire.goTo(parseInt($event.target.value) || 1)" aria-label="Page">
                        <button class="btn btn-sm" type="button" wire:click="goTo({{ $data['page'] + 1 }})" @disabled($data['page'] >= $data['pages'])>Next</button>
                        <button class="btn btn-sm" type="button" wire:click="goTo({{ $data['pages'] }})" @disabled($data['page'] >= $data['pages'])>Last</button>
                    </div>
                </div>
            @endif
        </x-layer-card>
    @endif

    {{-- One record --}}
    <x-modal-frame class="modal-wide" open="$wire.rowOpen" close="$wire.closeRow()">
        @if ($rowOpen)
            @php $columnMeta = collect($columns)->keyBy('name'); @endphp
            <div class="grid gap-4">
                <div class="modal-head">
                    <div class="min-w-0">
                        <p class="text-[12px] text-muted">{{ $creating ? 'New row' : ($editing ? 'Edit row' : 'Record') }}</p>
                        <h2 class="mt-1 truncate font-mono">{{ $table }}</h2>
                        @unless ($creating)
                            <p class="mt-1 font-mono text-[12px] text-muted">{{ collect($rowKeys)->map(fn ($value, $column) => $column.' = '.$value)->implode(' · ') }}</p>
                        @endunless
                    </div>
                    <button class="icon-btn" type="button" wire:click="closeRow" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>

                @if ($editing)
                    <form class="db-form max-h-[62vh] overflow-auto rounded-lg border border-line" wire:submit="saveRow" id="row-form">
                        @foreach ($columns as $column)
                            @php
                                $name = $column['name'];
                                $value = (string) ($form[$name]['value'] ?? '');
                                $isNull = ! empty($form[$name]['null']);
                                $kind = \App\Services\DatabaseBrowser::inputKind($column, $value);
                                $auto = str_contains($column['extra'], 'auto_increment');
                                $hint = $creating && ($auto || $column['default'] !== null) ? 'Leave blank for '.($auto ? 'the next number' : 'the default') : '';
                            @endphp
                            <div class="db-form-row" wire:key="field-{{ $name }}">
                                <div class="db-form-label">
                                    <span class="font-mono text-[13px] font-medium break-all">{{ $name }}</span>
                                    <span class="db-form-type" title="{{ $column['type'] }}">{{ \Illuminate\Support\Str::limit($column['type'], 40) }}</span>
                                    <span class="flex flex-wrap gap-1">
                                        @if ($column['key'] === 'PRI') <span class="pill">key</span> @endif
                                        @if ($auto) <span class="pill">auto</span> @endif
                                    </span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    @if ($kind === 'binary')
                                        <input class="input font-mono text-[13px]" value="{{ $value }}" disabled>
                                    @elseif ($kind === 'enum')
                                        <select class="select font-mono text-[13px]" wire:model="form.{{ $name }}.value" @disabled($isNull) aria-label="{{ $name }}">
                                            @if ($creating || $value === '') <option value="">{{ $hint !== '' ? $hint : 'Choose…' }}</option> @endif
                                            @foreach (\App\Services\DatabaseBrowser::enumOptions($column['type']) as $option)
                                                <option value="{{ $option }}">{{ $option }}</option>
                                            @endforeach
                                        </select>
                                    @elseif (in_array($kind, ['datetime', 'date', 'time'], true))
                                        <div class="flex flex-wrap items-center gap-2" x-data="{ set(value) { $wire.set('form.{{ $name }}.value', value) } }">
                                            <input class="input db-date min-w-0 flex-1 font-mono text-[13px]" type="{{ $kind === 'datetime' ? 'datetime-local' : $kind }}" step="1" wire:model="form.{{ $name }}.value" @disabled($isNull) aria-label="{{ $name }}">
                                            <button class="btn btn-sm" type="button" @disabled($isNull)
                                                @click="const d = new Date(), p = (n) => String(n).padStart(2, '0'), day = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()), time = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); set(@js($kind) === 'date' ? day : (@js($kind) === 'time' ? time : day + 'T' + time))">Now</button>
                                            <button class="btn btn-sm" type="button" @disabled($isNull) @click="set('')">Clear</button>
                                        </div>
                                    @elseif ($kind === 'long')
                                        <textarea class="textarea min-h-24 font-mono text-[13px]" wire:model="form.{{ $name }}.value" @disabled($isNull) spellcheck="false" aria-label="{{ $name }}" placeholder="{{ $hint }}"></textarea>
                                    @else
                                        <input class="input font-mono text-[13px]" wire:model="form.{{ $name }}.value" @disabled($isNull) aria-label="{{ $name }}" placeholder="{{ $hint }}" @if (in_array($kind, ['number', 'year'], true)) inputmode="decimal" @endif>
                                    @endif
                                </div>
                                @if ($column['nullable'])
                                    <label class="db-form-null" title="Store NULL (no value)">
                                        <input type="checkbox" wire:model.live="form.{{ $name }}.null"> NULL
                                    </label>
                                @else
                                    <span class="db-form-null"></span>
                                @endif
                            </div>
                        @endforeach
                    </form>
                    @error('form') <p class="error">{{ $message }}</p> @enderror
                    <div class="flex justify-end gap-2 border-t border-line pt-4">
                        <button class="btn" type="button" wire:click="{{ $creating ? 'closeRow' : '$set(\'editing\', false)' }}">Cancel</button>
                        <button class="btn btn-primary" type="submit" form="row-form" wire:loading.attr="disabled" wire:target="saveRow">{{ $creating ? 'Add row' : 'Save' }}</button>
                    </div>
                @else
                    <div class="max-h-[60vh] overflow-auto rounded-lg border border-line">
                        <dl class="divide-y divide-line">
                            @foreach ($rowData as $column => $value)
                                @php $meta = $columnMeta->get($column, []); @endphp
                                <div class="flex items-start gap-4 px-4 py-3" wire:key="value-{{ $column }}">
                                    <dt class="m-0 w-40 shrink-0">
                                        <span class="block truncate font-mono text-[13px] font-medium">{{ $column }}</span>
                                        @if ($meta !== [])
                                            <span class="mt-1 block font-mono text-[11px] text-muted">{{ $meta['type'] }}{{ ($meta['key'] ?? '') === 'PRI' ? ' · key' : '' }}</span>
                                        @endif
                                    </dt>
                                    <dd class="m-0 min-w-0 flex-1 font-mono text-[13px] break-words">
                                        @if ($value === null)
                                            <span class="italic text-muted">NULL</span>
                                        @elseif ($value === '')
                                            <span class="italic text-muted">empty</span>
                                        @elseif (isset($relations['out'][$column]))
                                            @php $to = $relations['out'][$column]; @endphp
                                            <a class="db-grid-link" href="{{ route('database.table', [$to['database'], $to['table']]).'?'.http_build_query(['where' => [$to['column'] => (string) $value]]) }}" wire:navigate>{{ $value }}</a>
                                            <span class="ml-1 text-[11.5px] text-muted">→ {{ $to['table'] }}.{{ $to['column'] }}</span>
                                        @else
                                            <span class="whitespace-pre-wrap break-words">{{ $value }}</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        @php $incoming = collect($relations['in'])->filter(fn ($list, $column) => ($rowData[$column] ?? null) !== null); @endphp
                        @if ($incoming->isNotEmpty())
                            <div class="border-t border-line px-4 py-3">
                                <p class="mb-2 text-[12px] font-medium text-muted">Related rows</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($incoming as $column => $list)
                                        @foreach ($list as $relation)
                                            <a class="btn btn-sm" href="{{ route('database.table', [$relation['database'], $relation['table']]).'?'.http_build_query(['where' => [$relation['column'] => (string) $rowData[$column]]]) }}" wire:navigate>
                                                <x-menu-icon name="table" class="size-4" /> <span class="font-mono">{{ $relation['table'] }}</span> <span class="font-mono text-muted">({{ $relation['column'] }})</span>
                                            </a>
                                        @endforeach
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="flex justify-between gap-2 border-t border-line pt-4">
                        @if ($canChange)
                            <button class="btn btn-danger" type="button" wire:click="deleteOpenRow"><x-menu-icon name="trash" /> Delete</button>
                            <button class="btn btn-primary" type="button" wire:click="editRow"><x-menu-icon name="pencil" /> Edit</button>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </x-modal-frame>

    {{-- Delete, with a copy first --}}
    <x-modal-frame class="modal-medium modal-root-top" open="$wire.deleteOpen" close="$wire.set('deleteOpen', false)">
        <div class="grid gap-4">
            <div class="modal-head">
                <div class="min-w-0">
                    <p class="text-[12px] text-muted">{{ $table }}</p>
                    <h2 class="mt-1">Delete {{ count($deleteKeys) }} {{ count($deleteKeys) === 1 ? 'row' : 'rows' }}?</h2>
                </div>
                <button class="icon-btn" type="button" wire:click="$set('deleteOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <p class="text-[13px] text-muted">Each deleted row is kept in the change log, so it can be put back on its own later, even when other rows changed meanwhile.</p>
            <label class="check-card">
                <input class="mt-0.5" type="checkbox" wire:model="saveCopy">
                <span><span class="block font-medium">Save a copy of the table {{ $table }} first</span><span class="block text-[12.5px] text-muted">A dump of this table right now, which the change log can put back whole.</span></span>
            </label>
            <label class="check-card">
                <input class="mt-0.5" type="checkbox" wire:model="backupFirst">
                <span><span class="block font-medium">Also back up the whole database {{ $database }}</span><span class="block text-[12.5px] text-muted">To your backup storage, in the background (Backups → Databases).</span></span>
            </label>
            <div class="flex justify-end gap-2">
                <button class="btn" type="button" wire:click="$set('deleteOpen', false)">Cancel</button>
                <button class="btn btn-danger" type="button" wire:click="confirmDelete" wire:loading.attr="disabled" wire:target="confirmDelete">Delete</button>
            </div>
        </div>
    </x-modal-frame>
</div>
