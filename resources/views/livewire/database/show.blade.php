<div class="flex flex-col gap-6" x-data="sqlImport({ database: @js($database), chunkUrl: @js(route('database.import.chunk', $database)), startUrl: @js(route('database.import.start', $database)) })">
    <div class="page-header">
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
                <a class="transition-colors hover:text-ink" href="{{ route('database.index') }}" wire:navigate>Database</a>
            </p>
            <h2 class="page-title mt-1 flex items-center gap-2 truncate font-mono"><x-menu-icon name="database" class="size-5 text-muted" /> {{ $database }}</h2>
            <p class="page-summary">{{ $tableCount }} tables · {{ \Illuminate\Support\Number::fileSize($totalSize, precision: 1) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <button class="btn" type="button" @click="open = true" @disabled($importing)><x-menu-icon name="upload" /> Import SQL</button>
            <button class="btn" type="button" wire:click="openExport"><x-menu-icon name="download" /> Export SQL</button>
            <button class="btn" type="button" wire:click="backup" wire:confirm="Back up {{ $database }} now to your backup storage?"><x-menu-icon name="backup" /> Back up</button>
        </div>
    </div>

    @include('livewire.database.partials.export')

    {{-- Import a dump: upload in pieces, then the background import with its progress. --}}
    <x-modal-frame class="modal-medium" open="open" close="if (! uploading) open = false">
        <div class="grid gap-4">
            <div class="modal-head">
                <div class="min-w-0">
                    <p class="text-[12px] text-muted">Import SQL</p>
                    <h2 class="mt-1 truncate font-mono">{{ $database }}</h2>
                </div>
                <button class="icon-btn" type="button" @click="if (! uploading) open = false" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <label class="grid gap-2">
                <span class="text-[13px] font-medium">Dump file</span>
                <input class="input" type="file" accept=".sql,.gz,.sql.gz" x-ref="file" @change="pick($event)" :disabled="uploading">
                <span class="text-[12px] text-muted">A .sql or .sql.gz file from mysqldump, phpMyAdmin, DataGrip, or a Laravelify backup. Any size: it is sent in 8 MB pieces.</span>
            </label>
            <label class="flex items-start gap-2.5 text-[13px]">
                <input type="checkbox" class="mt-0.5" x-model="reset" :disabled="uploading">
                <span><span class="font-medium">Empty the database first</span><span class="block text-[12px] text-muted">Deletes every table in {{ $database }} before the import. Use it when the dump creates tables that are already there.</span></span>
            </label>
            <template x-if="uploading">
                <div class="grid gap-2" role="status" aria-live="polite">
                    <div class="flex items-center justify-between text-[13px]">
                        <span class="flex items-center gap-2 font-medium"><span class="size-3.5 animate-spin rounded-full border-2 border-current border-t-transparent"></span> Uploading <span x-text="sizeLabel"></span></span>
                        <span class="tabular-nums text-muted" x-text="percent + '%'"></span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-recessed"><div class="h-full rounded-full bg-accent transition-all duration-300" :style="'width: ' + Math.max(2, percent) + '%'"></div></div>
                </div>
            </template>
            <p class="text-[12.5px] text-danger" x-show="error" x-text="error"></p>
            <div class="flex justify-end gap-2">
                <button class="btn" type="button" @click="open = false" :disabled="uploading">Cancel</button>
                <button class="btn btn-primary" type="button" @click="start()" :disabled="! file || uploading"><x-menu-icon name="upload" /> <span x-text="uploading ? 'Uploading…' : 'Upload and import'"></span></button>
            </div>
        </div>
    </x-modal-frame>

    @if ($importing)
        {{-- Refreshes every 2 seconds until the import finishes, in every open tab. --}}
        <div class="layer-card flex flex-col gap-2 p-4" wire:poll.2s role="status" aria-live="polite">
            <div class="flex items-center justify-between gap-3 text-[13px]">
                <span class="flex min-w-0 items-center gap-2 font-medium">
                    <span class="size-3.5 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"></span>
                    <span class="truncate">{{ $importing['label'] }}</span>
                </span>
                <span class="tabular-nums text-muted">{{ $importing['progress'] }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-recessed"><div class="h-full rounded-full bg-accent transition-all duration-700" style="width: {{ max(2, $importing['progress']) }}%"></div></div>
            <p class="text-[12.5px] text-muted">{{ $importing['step'] }} You can leave this page: the import goes on in the background.</p>
        </div>
    @endif

    <div class="segmented self-start" role="tablist">
        <button type="button" wire:click="$set('tab', 'tables')" aria-pressed="{{ $tab === 'tables' ? 'true' : 'false' }}">Tables</button>
        <button type="button" wire:click="$set('tab', 'sql')" aria-pressed="{{ $tab === 'sql' ? 'true' : 'false' }}">SQL</button>
        <button type="button" wire:click="$set('tab', 'history')" aria-pressed="{{ $tab === 'history' ? 'true' : 'false' }}">History</button>
        <button type="button" wire:click="$set('tab', 'objects')" aria-pressed="{{ $tab === 'objects' ? 'true' : 'false' }}">Triggers, events, routines</button>
        <button type="button" wire:click="$set('tab', 'search')" aria-pressed="{{ $tab === 'search' ? 'true' : 'false' }}">Search</button>
        <button type="button" wire:click="$set('tab', 'changes')" aria-pressed="{{ $tab === 'changes' ? 'true' : 'false' }}">Changes</button>
    </div>

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $error }}</p></div>
    @endif

    @if ($tab === 'tables')
        <x-layer-card title="Tables" helper="Rows and sizes are MySQL's own figures and can be a little behind. Tick tables to empty or drop them together." flush>
            <div x-data="{ count: 0 }" @grid-selection="count = $event.detail.count">
                <div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2.5" x-show="count > 0" x-cloak>
                    <span class="text-[13px] font-medium"><span x-text="count"></span> selected</span>
                    <button class="btn btn-sm" type="button"
                        @click="if (confirm('Empty ' + count + ' selected table(s) in {{ $database }}? Every row in them is deleted.')) { $wire.bulkTables('empty').then(() => count = 0) }">
                        <x-menu-icon name="trash" class="size-4" /> Empty selected
                    </button>
                    <button class="btn btn-sm btn-danger" type="button"
                        @click="if (confirm('Drop ' + count + ' selected table(s) from {{ $database }}? The tables and their rows are deleted.')) { $wire.bulkTables('drop').then(() => count = 0) }">
                        <x-menu-icon name="danger" class="size-4" /> Drop selected
                    </button>
                </div>
            @include('livewire.database.partials.local-grid', ['spec' => [
                'key' => 'tables-mysql',
                'select' => 'Table',
                'selectModel' => 'selectedTables',
                'search' => 'Search tables',
                'empty' => 'No tables.',
                'columns' => [
                    ['name' => 'Table', 'link' => true, 'width' => 300],
                    ['name' => 'Rows', 'numeric' => true, 'width' => 120],
                    ['name' => 'Size', 'numeric' => true, 'format' => 'bytes', 'width' => 120],
                    ['name' => 'Engine', 'width' => 120],
                    ['name' => 'Last change', 'width' => 180],
                    ['name' => 'Comment', 'width' => 260],
                ],
                'rows' => collect($tables)->map(fn (array $table): array => [
                    'Table' => $table['name'],
                    'Rows' => $table['rows'],
                    'Size' => $table['size'],
                    'Engine' => $table['engine'],
                    'Last change' => $table['updated'],
                    'Comment' => $table['comment'] !== '' ? $table['comment'] : null,
                    '__href' => route('database.table', [$database, $table['name']]),
                ])->values()->all(),
            ]])
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
    @elseif ($tab === 'sql')
        @include('livewire.database.partials.sql', ['sqlTarget' => $database])
    @elseif ($tab === 'history')
        @include('livewire.database.partials.sql-history')
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
                <div class="min-w-0">
                    <p class="text-[12px] text-muted">{{ $editorReadOnly ? 'View' : ($editorName ? 'Change' : 'New') }}</p>
                    <h2 class="mt-1 truncate font-mono">{{ $editorType }}{{ $editorName ? ' '.$editorName : '' }}</h2>
                </div>
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
