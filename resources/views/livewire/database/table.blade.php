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
<div class="flex flex-col gap-6" x-data="{ selected: [] }" @rows-deleted.window="selected = []" @db-grid-selected.window="selected = $event.detail">
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
        <x-layer-card title="Rows" helper="Scroll for more rows. Hover or click a cell to see it whole; double-click a row to open it. The funnel on a header filters by its values, the ⋮ sorts, pins, and hides columns." flush>
            <x-slot:actions>
                <input class="input h-8 w-56" type="search" wire:model.live.debounce.400ms="search" placeholder="Search every column" aria-label="Search every column">
                <button class="btn btn-sm" type="button" wire:click="addFilter"><x-menu-icon name="plus" class="size-4" /> Filter</button>
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
                            @if (($filter['operator'] ?? '') === 'in')
                                @php
                                    $chosen = (array) ($filter['values'] ?? []);
                                    $summary = collect($chosen)->take(4)->implode(', ').(count($chosen) > 4 ? ' and '.(count($chosen) - 4).' more' : '');
                                @endphp
                                <div class="field min-w-0 flex-1">
                                    <span class="label">Is one of</span>
                                    <p class="input flex items-center truncate font-mono text-[12.5px]">{{ $summary !== '' ? $summary : '' }}{{ ! empty($filter['blanks']) ? ($summary !== '' ? ', ' : '').'(empty)' : '' }}{{ $summary === '' && empty($filter['blanks']) ? 'nothing (no row matches)' : '' }}</p>
                                </div>
                            @else
                            <label class="field w-48">
                                <span class="label">Match</span>
                                <select class="select" wire:model.live="filters.{{ $index }}.operator" aria-label="How it matches">
                                    @foreach ($operators as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @endif
                            @if (($filter['operator'] ?? '') === 'in')
                            @elseif (! in_array($filter['operator'] ?? '', ['null', 'not_null'], true))
                                <label class="field min-w-0 flex-1">
                                    <span class="label">Value</span>
                                    <input class="input font-mono text-[12.5px]" wire:model.live.debounce.400ms="filters.{{ $index }}.value" placeholder="What to match" aria-label="Value">
                                </label>
                            @else
                                <span></span>
                            @endif
                            @if ($cryptoMethods->isNotEmpty() && ! in_array($filter['operator'] ?? '', ['null', 'not_null', 'in'], true))
                                <label class="field w-48">
                                    <span class="label">Encrypted with</span>
                                    <select class="select" wire:model.live="filters.{{ $index }}.crypto" aria-label="The column is encrypted with">
                                        <option value="">Not encrypted</option>
                                        @foreach ($cryptoMethods as $method)
                                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endif
                            <button class="icon-btn" type="button" wire:click="removeFilter({{ $index }})" aria-label="Remove filter"><x-menu-icon name="close" class="size-4" /></button>
                        </div>
                    @endforeach
                    @error('filters') <p class="error">{{ $message }}</p> @enderror
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
                $gridPayload = [
                    'database' => $database,
                    'table' => $table,
                    'urlTemplate' => route('database.table', ['__DB__', '__TABLE__']),
                    'columns' => collect($columns)->map(fn ($column) => [
                        'name' => $column['name'],
                        'type' => $column['type'],
                        'dataType' => $column['data_type'],
                        'key' => in_array($column['name'], $keyColumns, true),
                        'numeric' => in_array($column['data_type'], ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'float', 'double', 'year'], true),
                        'out' => $relations['out'][$column['name']] ?? null,
                        'in' => $relations['in'][$column['name']] ?? [],
                    ])->values()->all(),
                    'total' => $data['total'],
                    'version' => md5(json_encode([$search, $filters, $encryptedValues])),
                    'filtered' => collect($filters)->pluck('column')->filter()->unique()->values()->all(),
                    'columnFilters' => (object) collect($filters)->filter(fn ($filter) => ($filter['column'] ?? '') !== '')->keyBy('column')->all(),
                    'operators' => $operators,
                    'keyColumns' => $keyColumns,
                    'canChange' => $canChange,
                    'sort' => $sort,
                    'direction' => $direction,
                    'anyFilter' => $search !== '' || $filters !== [],
                ];
                $gridJson = json_encode($gridPayload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            @endphp
            {{-- The rows for the grid. A new page from the server replaces this element, which hands the rows over. --}}
            <div hidden data-db-grid-payload="{{ $gridJson }}" wire:key="grid-data-{{ md5((string) $gridJson) }}" x-data x-init="$dispatch('db-grid-data', JSON.parse($el.dataset.dbGridPayload))"></div>
            <div wire:ignore x-data="dbGrid()" @db-grid-data.window="receive($event.detail)">
                <div x-ref="grid" class="db-ag-grid"></div>
            </div>

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
                    @php $decryptMethods = $cryptoMethods->filter(fn ($method) => $method->canDecrypt()); @endphp
                    @if ($decryptMethods->isNotEmpty())
                        <form class="flex flex-wrap items-center gap-2" wire:submit="decryptRow">
                            <select class="select h-8 w-auto" wire:model="decryptWith" aria-label="Decrypt with">
                                <option value="">Show decrypted with…</option>
                                @foreach ($decryptMethods as $method)
                                    <option value="{{ $method->id }}">{{ $method->name }}</option>
                                @endforeach
                            </select>
                            <button class="btn btn-sm" type="submit" wire:loading.attr="disabled" wire:target="decryptRow">Decrypt</button>
                        </form>
                    @endif
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
                                        @isset($decrypted[$column])
                                            <span class="mb-1 flex items-start gap-2 rounded-md px-2 py-1" style="background: color-mix(in srgb, var(--color-success) 10%, transparent)"><span class="shrink-0 text-[11px] text-muted">decrypted</span> <span class="whitespace-pre-wrap break-words">{{ $decrypted[$column] }}</span></span>
                                        @endisset
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
