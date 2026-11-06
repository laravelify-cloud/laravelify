@php
    $title = $connection?->name ?? $database;
    $home = route('database.engine', [$routeEngine, $database]);
@endphp
<div class="flex flex-col gap-6" x-data="{ selected: [] }" @rows-deleted.window="selected = []" @db-grid-selected.window="selected = $event.detail">
    <div class="page-header">
        <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted">
                <a class="transition-colors hover:text-ink" href="{{ route('database.index') }}" wire:navigate>Database</a>
                @if ($table !== '')
                    <x-menu-icon name="chevron-right" class="size-3.5 opacity-60" />
                    <a class="font-mono transition-colors hover:text-ink" href="{{ $home }}" wire:navigate>{{ $title }}</a>
                @endif
            </p>
            <h2 class="page-title mt-1 flex items-center gap-2 truncate font-mono">
                <x-menu-icon :name="$table !== '' ? 'table' : 'database'" class="size-5 text-muted" /> {{ $table !== '' ? $table : $title }}
                <span class="db-engine" data-engine="{{ $connection ? $connection->driver : $engine }}">{{ $label }}</span>
            </h2>
            <p class="page-summary">
                @if ($table === '')
                    {{ count($tables) }} {{ $isMongo ? 'collections' : 'tables' }}{{ $engine === 'sqlite' ? ' · the SQLite file of the website '.$database : '' }}{{ $connection ? ' · '.$connection->username.'@'.$connection->host.':'.$connection->port.' / '.$connection->database : '' }}
                @elseif ($result)
                    {{ number_format($result['total']) }} {{ $isMongo ? 'documents' : 'rows' }}{{ $search !== '' ? ' match' : '' }}
                @elseif ($grid)
                    {{ number_format($grid['total']) }} {{ $grid['total'] === 1 ? 'row' : 'rows' }}{{ $grid['anyFilter'] ? ' match' : '' }}{{ $grid['canChange'] ? ' · key: '.implode(', ', $grid['keyColumns']) : ' · no primary key, so rows are read-only here' }}
                @endif
            </p>
        </div>
        @if ($table === '' && array_key_exists($engine, \App\Services\EngineDatabases::DUMP_EXTENSIONS))
            <div class="flex flex-wrap items-center gap-1.5">
                <button class="btn" type="button" wire:click="export"><x-menu-icon name="download" /> Export</button>
                <button class="btn" type="button" wire:click="backup" wire:confirm="Back up {{ $title }} now to your backup storage?"><x-menu-icon name="backup" /> Back up</button>
            </div>
        @endif
        @if ($table !== '' && $tab === 'tables' && ($isMongo || ($grid['canChange'] ?? false)))
            <div class="flex flex-wrap items-center gap-1.5">
                @unless ($isMongo)
                    <button class="btn btn-danger" type="button" x-show="selected.length" x-cloak
                        @click="if (confirm('Delete ' + selected.length + (selected.length === 1 ? ' row' : ' rows') + '? It cannot be undone.')) $wire.deleteKeys(selected)">
                        <x-menu-icon name="trash" /> Delete <span x-text="selected.length"></span>
                    </button>
                @endunless
                <button class="btn btn-primary" type="button" wire:click="newRow"><x-menu-icon name="plus" /> {{ $isMongo ? 'New document' : 'Add row' }}</button>
            </div>
        @endif
    </div>

    @unless ($isMongo)
        <div class="segmented self-start" role="tablist">
            <button type="button" wire:click="$set('tab', 'tables')" aria-pressed="{{ $tab === 'tables' ? 'true' : 'false' }}">{{ $table !== '' ? 'Rows' : 'Tables' }}</button>
            <button type="button" wire:click="$set('tab', 'sql')" aria-pressed="{{ $tab === 'sql' ? 'true' : 'false' }}">SQL</button>
            <button type="button" wire:click="$set('tab', 'history')" aria-pressed="{{ $tab === 'history' ? 'true' : 'false' }}">History</button>
        </div>
    @endunless

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $error }}</p></div>
    @endif

    @if ($tab === 'sql' && ! $isMongo)
        <x-layer-card title="SQL" helper="One statement at a time, run in {{ $title }}{{ $connection ? ' as '.$connection->username : ($engine === 'sqlite' ? ' as the website\'s user' : ' as '.config('laravelify.postgres_user', 'postgres')) }}. The first 1,000 rows of a result are shown. Changes made here cannot be undone.">
            <div class="grid gap-3" x-data="{ run(selection, sql) { $wire.runSql(selection, sql); } }">
                @include('livewire.database.partials.sql-editor', [
                    'dialect' => $connection?->driver ?? ($engine === 'sqlite' ? 'sqlite' : 'pgsql'),
                    'placeholder' => ($connection?->driver) === 'sqlsrv' ? 'SELECT TOP 10 * FROM users' : 'SELECT * FROM users LIMIT 10',
                ])
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[12.5px] text-muted">Ctrl+Enter runs it; a selection runs on its own. Ctrl+Space shows the tables and columns.</p>
                    <button class="btn btn-primary" type="button" @click="$dispatch('sql-run')"><x-menu-icon name="play" /> Run</button>
                </div>
            </div>
            @if ($sqlError)
                <p class="error mt-3">{{ $sqlError }}</p>
            @elseif ($sqlResult)
                @if ($sqlResult['columns'] === [])
                    <p class="mt-3 text-[13px] text-muted">Done. {{ $sqlResult['affected'] }} {{ \Illuminate\Support\Str::plural('row', $sqlResult['affected']) }} changed.</p>
                @else
                    <p class="mt-3 text-[12.5px] text-muted">{{ count($sqlResult['rows']) }} {{ count($sqlResult['rows']) === 1 ? 'row' : 'rows' }}</p>
                    <div class="mt-2 overflow-hidden rounded-lg border border-line">
                        @include('livewire.database.partials.result-grid', ['columns' => $sqlResult['columns'], 'rows' => $sqlResult['rows']])
                    </div>
                @endif
            @endif
        </x-layer-card>
    @elseif ($tab === 'history' && ! $isMongo)
        @include('livewire.database.partials.sql-history')
    @elseif ($table === '')
        <x-layer-card :title="$isMongo ? 'Collections' : 'Tables'" :helper="$isMongo ? 'Document counts are MongoDB’s estimates.' : ($engine === 'sqlite' ? 'Exact row counts.' : 'Rows and sizes are the server’s own estimates and can be a little behind.')" flush>
            @include('livewire.database.partials.local-grid', ['spec' => [
                'key' => 'tables-'.$engine,
                'search' => $isMongo ? 'Search collections' : 'Search tables',
                'empty' => $isMongo ? 'No collections.' : 'No tables.',
                'columns' => array_values(array_filter([
                    ['name' => $isMongo ? 'Collection' : 'Table', 'link' => true, 'width' => 340],
                    ['name' => $isMongo ? 'Documents' : 'Rows', 'numeric' => true, 'width' => 140],
                    $engine === 'sqlite' ? null : ['name' => 'Size', 'numeric' => true, 'format' => 'bytes', 'width' => 130],
                ])),
                'rows' => collect($tables)->map(fn (array $item): array => array_filter([
                    $isMongo ? 'Collection' : 'Table' => $item['name'],
                    $isMongo ? 'Documents' : 'Rows' => $item['rows'],
                    'Size' => $engine === 'sqlite' ? false : $item['size'],
                    '__href' => $home.'?table='.urlencode($item['name']),
                ], fn ($value) => $value !== false))->values()->all(),
            ]])
        </x-layer-card>
    @elseif ($grid)
        <x-layer-card title="Rows" helper="Scroll for more rows. Hover or click a cell to see it whole; double-click a row to open it. The funnel on a header filters by its values, the ⋮ sorts, pins, and hides columns." flush>
            <x-slot:actions>
                @if ($grid['anyFilter'])
                    <button class="btn btn-sm" type="button" wire:click="clearFilters">Clear filters</button>
                @endif
                <input class="input h-8 w-56" type="search" wire:model.live.debounce.400ms="search" placeholder="Search every column" aria-label="Search every column">
            </x-slot:actions>
            @php
                $gridJson = json_encode($grid, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            @endphp
            {{-- The grid's settings. A new render replaces this element, which hands them over. --}}
            <div hidden data-db-grid-payload="{{ $gridJson }}" wire:key="grid-data-{{ md5((string) $gridJson) }}" x-data x-init="$dispatch('db-grid-data', JSON.parse($el.dataset.dbGridPayload))"></div>
            <div wire:ignore x-data="dbGrid()" @db-grid-data.window="receive($event.detail)">
                <div x-ref="grid" class="db-ag-grid"></div>
            </div>
        </x-layer-card>
    @elseif ($result)
        <x-layer-card title="Documents" helper="Newest first. Filter with MongoDB's JSON query, like {&quot;status&quot;: &quot;active&quot;}. Changes here cannot be undone." flush>
            <x-slot:actions>
                <input class="input h-8 w-72 font-mono text-[12.5px]" type="search" wire:model.live.debounce.400ms="search" placeholder="{&quot;status&quot;: &quot;active&quot;}" aria-label="Filter">
            </x-slot:actions>
            <div class="db-docs">
                @forelse ($result['rows'] as $index => $document)
                    <div class="db-doc" wire:key="doc-{{ $document['id'] }}">
                        <pre>{{ $document['json'] }}</pre>
                        <div class="row-actions">
                            <button class="icon-btn" type="button" wire:click="editRow({{ $index }})" title="Change" aria-label="Change"><x-menu-icon name="pencil" /></button>
                            <button class="icon-btn" type="button" wire:click="deleteDocument(@js($document['id']))" wire:confirm="Delete this document? It cannot be undone." title="Delete" aria-label="Delete"><x-menu-icon name="trash" /></button>
                        </div>
                    </div>
                @empty
                    <p class="table-empty">No documents{{ $search !== '' ? ' match' : '' }}.</p>
                @endforelse
            </div>
            @if ($result['total'] > $perPage)
                <div class="db-pager">
                    <span>{{ number_format(($page - 1) * $perPage + 1).'–'.number_format(min($page * $perPage, $result['total'])).' of '.number_format($result['total']) }}</span>
                    <button class="btn btn-sm" type="button" wire:click="goToPage({{ $page - 1 }})" @disabled($page <= 1)>Previous</button>
                    <button class="btn btn-sm" type="button" wire:click="goToPage({{ $page + 1 }})" @disabled($page * $perPage >= $result['total'])>Next</button>
                </div>
            @endif
        </x-layer-card>
    @endif

    <x-modal-frame class="modal-wide" open="$wire.editOpen" close="$wire.set('editOpen', false)">
        <form class="grid gap-4" wire:submit="save">
            <div class="modal-head">
                <div class="min-w-0">
                    <p class="text-[12px] text-muted">{{ ($isMongo ? $documentId : $editKeys) === null ? 'New' : 'Change' }} {{ $isMongo ? 'document' : 'row' }}</p>
                    <h2 class="mt-1 truncate font-mono">{{ $table }}</h2>
                </div>
                <button class="icon-btn" type="button" wire:click="$set('editOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            @if ($isMongo)
                <textarea class="textarea min-h-80 font-mono text-[12.5px]" wire:model="documentJson" spellcheck="false" aria-label="Document"></textarea>
                <p class="text-[12.5px] text-muted">Extended JSON: {"$oid": "…"} for ids, {"$date": "…"} for dates. {{ $documentId ? 'Saving replaces the whole document; its _id stays.' : 'Leave out _id and MongoDB makes one.' }}</p>
            @else
                <div class="db-fields">
                    @foreach ($editValues as $column => $value)
                        <label class="db-field" wire:key="f-{{ $column }}">
                            <span class="font-mono">{{ $column }}</span>
                            <textarea class="textarea min-h-9 font-mono text-[12.5px]" rows="1" wire:model="editValues.{{ $column }}" @disabled($editNulls[$column] ?? false) placeholder="{{ $editKeys === null ? 'Empty: the column\'s default' : '' }}"></textarea>
                            <span class="flex items-center gap-1.5 text-[12px] text-muted"><input type="checkbox" wire:model.live="editNulls.{{ $column }}"> NULL</span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('edit') <p class="error">{{ $message }}</p> @enderror
            <div class="flex justify-end gap-2">
                @if (! $isMongo && $editKeys !== null)
                    <button class="btn btn-danger mr-auto" type="button" wire:click="deleteOpenRow" wire:confirm="Delete this row? It cannot be undone."><x-menu-icon name="trash" /> Delete</button>
                @endif
                <button class="btn" type="button" wire:click="$set('editOpen', false)">Cancel</button>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </x-modal-frame>
</div>
