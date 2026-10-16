<div class="flex flex-col gap-6">
    <div class="page-header">
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
                <a class="transition-colors hover:text-ink" href="{{ route('database.index') }}" wire:navigate>Database</a>
            </p>
            <h2 class="page-title mt-1 flex items-center gap-2 truncate font-mono"><x-menu-icon name="database" class="size-5 text-muted" /> {{ $database }}</h2>
            <p class="page-summary">{{ $tableCount }} tables · {{ \Illuminate\Support\Number::fileSize($totalSize, precision: 1) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <button class="btn" type="button" wire:click="backup" wire:confirm="Back up {{ $database }} now to your backup storage?"><x-menu-icon name="backup" /> Back up</button>
        </div>
    </div>

    <div class="segmented self-start" role="tablist">
        <button type="button" wire:click="$set('tab', 'tables')" aria-pressed="{{ $tab === 'tables' ? 'true' : 'false' }}">Tables</button>
        <button type="button" wire:click="$set('tab', 'objects')" aria-pressed="{{ $tab === 'objects' ? 'true' : 'false' }}">Triggers, events, routines</button>
        <button type="button" wire:click="$set('tab', 'search')" aria-pressed="{{ $tab === 'search' ? 'true' : 'false' }}">Search</button>
        <button type="button" wire:click="$set('tab', 'changes')" aria-pressed="{{ $tab === 'changes' ? 'true' : 'false' }}">Changes</button>
    </div>

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $error }}</p></div>
    @endif

    @if ($tab === 'tables')
        <x-layer-card title="Tables" helper="Rows and sizes are MySQL's own figures and can be a little behind." flush>
            <x-slot:actions>
                <input class="input h-8 w-48" type="search" wire:model.live.debounce.250ms="filter" placeholder="Filter tables" aria-label="Filter tables">
                <div class="segmented" role="group" aria-label="Sort">
                    <button type="button" wire:click="$set('sort', 'name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}" class="text-[12px]">Name</button>
                    <button type="button" wire:click="$set('sort', 'size')" aria-pressed="{{ $sort === 'size' ? 'true' : 'false' }}" class="text-[12px]">Size</button>
                    <button type="button" wire:click="$set('sort', 'rows')" aria-pressed="{{ $sort === 'rows' ? 'true' : 'false' }}" class="text-[12px]">Rows</button>
                </div>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Table</th><th>Rows</th><th>Size</th><th>Engine</th><th>Last change</th></tr></thead>
                    <tbody>
                        @forelse ($tables as $table)
                            <tr wire:key="table-{{ $table['name'] }}">
                                <td>
                                    <a class="flex items-center gap-2 font-mono text-[13px] font-medium hover:text-accent" href="{{ route('database.table', [$database, $table['name']]) }}" wire:navigate>
                                        <x-menu-icon name="table" class="size-4 text-muted" /> {{ $table['name'] }}
                                    </a>
                                    @if ($table['comment'] !== '')
                                        <span class="block text-[12px] text-muted">{{ $table['comment'] }}</span>
                                    @endif
                                </td>
                                <td class="tabular-nums text-muted">{{ number_format($table['rows']) }}</td>
                                <td class="tabular-nums text-muted">{{ \Illuminate\Support\Number::fileSize($table['size'], precision: 1) }}</td>
                                <td class="text-muted">{{ $table['engine'] }}</td>
                                <td class="text-muted">{{ $table['updated'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="table-empty">No tables{{ $filter !== '' ? ' match' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-layer-card>
    @elseif ($tab === 'objects')
        @foreach (['trigger' => 'Triggers', 'event' => 'Events', 'procedure' => 'Procedures', 'function' => 'Functions'] as $type => $label)
            <x-layer-card :title="$label" helper="{{ ['trigger' => 'Run on INSERT, UPDATE, or DELETE of a table.', 'event' => 'Run on a schedule by MySQL (the event scheduler must be on).', 'procedure' => 'Stored procedures, run with CALL.', 'function' => 'Stored functions, used in queries.'][$type] }}" flush>
                <x-slot:actions>
                    <button class="btn btn-sm" type="button" wire:click="newObject('{{ $type }}')"><x-menu-icon name="plus" class="size-4" /> New</button>
                </x-slot:actions>
                <div class="overflow-x-auto">
                    <table class="table">
                        <tbody>
                            @forelse ($objects[$type] as $object)
                                <tr wire:key="{{ $type }}-{{ $object['name'] }}">
                                    <td class="font-mono text-[13px] font-medium">{{ $object['name'] }}</td>
                                    <td class="text-[12.5px] text-muted">
                                        @if ($type === 'trigger')
                                            {{ $object['timing'] }} {{ $object['happens'] }} on <span class="font-mono">{{ $object['on_table'] }}</span>
                                        @elseif ($type === 'event')
                                            {{ $object['status'] }} · {{ $object['kind'] === 'RECURRING' ? 'every '.$object['every'].' '.strtolower((string) $object['unit']) : 'once at '.$object['at'] }}{{ $object['last_run'] ? ' · last run '.$object['last_run'] : '' }}
                                        @else
                                            Changed {{ $object['changed'] }}
                                        @endif
                                    </td>
                                    <td>
                                        <div class="row-actions w-full">
                                            <button class="icon-btn" type="button" wire:click="viewObject('{{ $type }}', '{{ $object['name'] }}')" title="View" aria-label="View"><x-menu-icon name="eye" /></button>
                                            <button class="icon-btn" type="button" wire:click="editObject('{{ $type }}', '{{ $object['name'] }}')" title="Change" aria-label="Change"><x-menu-icon name="pencil" /></button>
                                            <button class="icon-btn" type="button" wire:click="dropObject('{{ $type }}', '{{ $object['name'] }}')" wire:confirm="Drop the {{ $type }} {{ $object['name'] }}? Its definition is kept in the change log, where Undo creates it again." title="Drop" aria-label="Drop"><x-menu-icon name="trash" /></button>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="table-empty" colspan="3">No {{ strtolower($label) }}.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-layer-card>
        @endforeach
    @elseif ($tab === 'search')
        @include('livewire.database.partials.search', ['scopeChoices' => [$database], 'fixedScope' => true])
    @else
        <x-layer-card title="Changes in {{ $database }}" helper="Newest first. Undo puts back only that row or object." flush>
            @include('livewire.database.partials.changes', ['changes' => $changes])
        </x-layer-card>
    @endif

    <x-modal-frame class="modal-wide" open="$wire.editorOpen" close="$wire.set('editorOpen', false)">
        <form class="grid gap-4" wire:submit="saveObject">
            <div class="modal-head">
                <h2>{{ $editorReadOnly ? '' : ($editorName ? 'Change ' : 'New ') }}{{ $editorType }}{{ $editorName ? ' '.$editorName : '' }}</h2>
                <button class="icon-btn" type="button" wire:click="$set('editorOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <textarea class="textarea min-h-72 font-mono text-[12.5px]" wire:model="editorSql" spellcheck="false" @readonly($editorReadOnly) aria-label="Definition"></textarea>
            @error('editorSql') <p class="error">{{ $message }}</p> @enderror
            @unless ($editorReadOnly)
                <p class="text-[12.5px] text-muted">One CREATE statement, without DELIMITER lines. {{ $editorName ? 'Saving replaces the old one; if MySQL refuses the new one, the old one is put back.' : '' }} The change log keeps both, so it can be undone.</p>
            @endunless
            <div class="flex justify-end gap-2">
                <button class="btn" type="button" wire:click="$set('editorOpen', false)">Close</button>
                @if ($editorReadOnly)
                    <button class="btn" type="button" wire:click="editObject('{{ $editorType }}', '{{ $editorName }}')">Change</button>
                @else
                    <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="saveObject">Save</button>
                @endif
            </div>
        </form>
    </x-modal-frame>
</div>
