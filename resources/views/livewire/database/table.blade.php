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
            @if ($canChange)
                <button class="btn btn-primary" type="button" wire:click="newRow"><x-menu-icon name="plus" /> Add row</button>
            @endif
        </div>
    </div>

    <div class="segmented self-start" role="tablist">
        <button type="button" wire:click="$set('tab', 'browse')" aria-pressed="{{ $tab === 'browse' ? 'true' : 'false' }}">Rows</button>
        <button type="button" wire:click="$set('tab', 'structure')" aria-pressed="{{ $tab === 'structure' ? 'true' : 'false' }}">Structure</button>
    </div>

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $error }}</p></div>
    @endif

    @if ($tab === 'structure')
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
                <select class="input h-8 w-auto" wire:model.live="perPage" data-native aria-label="Rows per page">
                    @foreach ([25, 50, 100, 250, 500] as $count)
                        <option value="{{ $count }}">{{ $count }} per page</option>
                    @endforeach
                </select>
            </x-slot:actions>

            @if ($filters !== [])
                <div class="flex flex-col gap-2 border-b border-line p-3">
                    @foreach ($filters as $index => $filter)
                        <div class="flex flex-wrap items-center gap-2" wire:key="filter-{{ $index }}">
                            <select class="input h-8 w-auto font-mono text-[12.5px]" wire:model.live="filters.{{ $index }}.column" data-native aria-label="Column">
                                <option value="">Column…</option>
                                @foreach ($columns as $column)
                                    <option value="{{ $column['name'] }}">{{ $column['name'] }}</option>
                                @endforeach
                            </select>
                            <select class="input h-8 w-auto" wire:model.live="filters.{{ $index }}.operator" data-native aria-label="Operator">
                                @foreach ($operators as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @unless (in_array($filter['operator'] ?? '', ['null', 'not_null'], true))
                                <input class="input h-8 w-56 font-mono text-[12.5px]" wire:model.live.debounce.400ms="filters.{{ $index }}.value" placeholder="value" aria-label="Value">
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

            <div class="overflow-x-auto">
                <table class="table db-rows">
                    <thead>
                        <tr>
                            @if ($canChange)
                                <th class="w-10"></th>
                            @endif
                            @foreach ($columns as $column)
                                <th>
                                    <button class="flex items-center gap-1 font-mono text-[12px] whitespace-nowrap" type="button" wire:click="sortBy('{{ $column['name'] }}')" title="{{ $column['type'] }}">
                                        {{ $column['name'] }}
                                        @if (in_array($column['name'], $keyColumns, true)) <span class="text-accent">•</span> @endif
                                        @if ($sort === $column['name']) <span>{{ $direction === 'asc' ? '↑' : '↓' }}</span> @endif
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data['rows'] as $index => $row)
                            @php($key = json_encode($keyOf($row)))
                            <tr wire:key="row-{{ $data['page'] }}-{{ $index }}-{{ md5($key) }}" class="cursor-pointer" :class="selected.includes(@js($key)) && 'is-selected'" @if ($canChange) wire:click="openRow(@js($keyOf($row)))" @endif>
                                @if ($canChange)
                                    <td @click.stop>
                                        <input type="checkbox" aria-label="Select row" :checked="selected.includes(@js($key))" @change="selected = $event.target.checked ? [...selected, @js($key)] : selected.filter((item) => item !== @js($key))">
                                    </td>
                                @endif
                                @foreach ($columns as $column)
                                    @php($value = $cell($row[$column['name']] ?? null))
                                    <td class="max-w-[22rem] truncate font-mono text-[12.5px]">
                                        @if ($value === null)
                                            <span class="text-muted italic">NULL</span>
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($columns) + 1 }}" class="table-empty">{{ $search !== '' || $filters !== [] ? 'No rows match.' : 'This table is empty.' }}</td></tr>
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
            <div class="grid gap-4">
                <div class="modal-head">
                    <div>
                        <h2>{{ $creating ? 'New row' : ($editing ? 'Edit row' : 'Row') }} <span class="font-mono text-muted">{{ $table }}</span></h2>
                        @unless ($creating)
                            <p class="font-mono text-[12px] text-muted">{{ collect($rowKeys)->map(fn ($value, $column) => $column.' = '.$value)->implode(', ') }}</p>
                        @endunless
                    </div>
                    <button class="icon-btn" type="button" wire:click="closeRow" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>

                @if ($editing)
                    <form class="grid max-h-[60vh] gap-3 overflow-auto pr-1" wire:submit="saveRow" id="row-form">
                        @foreach ($columns as $column)
                            @php($binary = str_starts_with((string) ($form[$column['name']]['value'] ?? ''), '[binary, '))
                            <div class="grid gap-1" wire:key="field-{{ $column['name'] }}">
                                <label class="flex items-center justify-between gap-2 text-[12.5px]">
                                    <span><span class="font-mono font-medium">{{ $column['name'] }}</span> <span class="text-muted">{{ $column['type'] }}{{ str_contains($column['extra'], 'auto_increment') ? ' · auto' : '' }}</span></span>
                                    @if ($column['nullable'])
                                        <span class="flex items-center gap-1 text-muted"><input type="checkbox" wire:model.live="form.{{ $column['name'] }}.null"> NULL</span>
                                    @endif
                                </label>
                                @if ($binary)
                                    <input class="input font-mono text-[12.5px]" value="{{ $form[$column['name']]['value'] }}" disabled>
                                @elseif (in_array($column['data_type'], ['text', 'mediumtext', 'longtext', 'json'], true) || mb_strlen((string) ($form[$column['name']]['value'] ?? '')) > 80)
                                    <textarea class="textarea min-h-24 font-mono text-[12.5px]" wire:model="form.{{ $column['name'] }}.value" @disabled(! empty($form[$column['name']]['null'])) spellcheck="false"></textarea>
                                @else
                                    <input class="input font-mono text-[12.5px]" wire:model="form.{{ $column['name'] }}.value" @disabled(! empty($form[$column['name']]['null'])) placeholder="{{ $creating && (str_contains($column['extra'], 'auto_increment') || $column['default'] !== null) ? 'blank = '.(str_contains($column['extra'], 'auto_increment') ? 'next number' : 'default') : '' }}">
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
                        <table class="table">
                            <tbody>
                                @foreach ($rowData as $column => $value)
                                    <tr wire:key="value-{{ $column }}">
                                        <td class="w-48 align-top font-mono text-[12.5px] text-muted">{{ $column }}</td>
                                        <td class="font-mono text-[12.5px] whitespace-pre-wrap break-all">@if ($value === null)<span class="text-muted italic">NULL</span>@else{{ $value }}@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
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
                <h2>Delete {{ count($deleteKeys) }} {{ count($deleteKeys) === 1 ? 'row' : 'rows' }}?</h2>
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
