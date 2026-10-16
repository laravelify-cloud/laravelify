<div class="flex flex-col gap-6">
    <x-page-header title="Database" summary="Every MySQL database on this server: browse, search, and change tables, rows, triggers, events, procedures, and functions. Every change is logged, so one row or object can be undone later.">
        <x-slot:actions>
            <a class="btn" href="{{ route('backups.databases') }}" wire:navigate><x-menu-icon name="backup" /> Backups</a>
        </x-slot:actions>
    </x-page-header>

    <div class="segmented self-start" role="tablist">
        <button type="button" wire:click="$set('tab', 'databases')" aria-pressed="{{ $tab === 'databases' ? 'true' : 'false' }}">Databases</button>
        <button type="button" wire:click="$set('tab', 'search')" aria-pressed="{{ $tab === 'search' ? 'true' : 'false' }}">Search everywhere</button>
        <button type="button" wire:click="$set('tab', 'changes')" aria-pressed="{{ $tab === 'changes' ? 'true' : 'false' }}">Changes</button>
    </div>

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">Could not reach MySQL: {{ $error }}</p></div>
    @endif

    @if ($tab === 'databases')
        <x-layer-card title="Databases" helper="{{ count($allDatabases) }} databases · {{ \Illuminate\Support\Number::fileSize($totalSize, precision: 1) }} in all. Sizes are MySQL's own figures and can be a little behind." flush>
            <x-slot:actions>
                <input class="input h-8 w-48" type="search" wire:model.live.debounce.250ms="filter" placeholder="Filter databases" aria-label="Filter databases">
                <div class="segmented" role="group" aria-label="Sort">
                    <button type="button" wire:click="$set('sort', 'name')" aria-pressed="{{ $sort === 'name' ? 'true' : 'false' }}" class="text-[12px]">Name</button>
                    <button type="button" wire:click="$set('sort', 'size')" aria-pressed="{{ $sort === 'size' ? 'true' : 'false' }}" class="text-[12px]">Size</button>
                </div>
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Database</th><th>Tables</th><th>Size</th><th class="w-56"></th></tr></thead>
                    <tbody>
                        @forelse ($databases as $database)
                            <tr wire:key="db-{{ $database['name'] }}">
                                <td>
                                    <a class="flex items-center gap-2 font-mono text-[13px] font-medium hover:text-accent" href="{{ route('database.show', $database['name']) }}" wire:navigate>
                                        <x-menu-icon name="database" class="size-4 text-muted" /> {{ $database['name'] }}
                                    </a>
                                </td>
                                <td class="text-muted">{{ $database['tables'] }}</td>
                                <td class="text-muted">{{ \Illuminate\Support\Number::fileSize($database['size'], precision: 1) }}</td>
                                <td>
                                    <div class="row-actions w-full">
                                        <a class="btn btn-sm" href="{{ route('database.show', $database['name']) }}" wire:navigate>Open</a>
                                        <button class="btn btn-sm" type="button" wire:click="backup('{{ $database['name'] }}')" wire:confirm="Back up {{ $database['name'] }} now to your backup storage?"><x-menu-icon name="backup" class="size-4" /> Back up</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="table-empty">No databases{{ $filter !== '' ? ' match' : '' }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-layer-card>
    @elseif ($tab === 'search')
        @include('livewire.database.partials.search', ['scopeChoices' => $allDatabases])
    @else
        <x-layer-card title="Changes" helper="Rows added, edited, and deleted here, and triggers, events, procedures, and functions changed here, newest first. Undo puts back only that row or object; when someone changed the row since, you are asked first." flush>
            @include('livewire.database.partials.changes', ['changes' => $changes])
        </x-layer-card>
    @endif
</div>
