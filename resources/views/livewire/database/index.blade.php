<div class="flex flex-col gap-6">
    <x-page-header title="Database" summary="Browse every MySQL database on this server. Search, edit rows, and undo a change later.">
        <x-slot:actions>
            <a class="btn" href="{{ route('backups.databases') }}" wire:navigate><x-menu-icon name="backup" /> Backups</a>
        </x-slot:actions>
    </x-page-header>

    @include('livewire.database.partials.export')

    <div class="segmented self-start" role="tablist">
        <button type="button" wire:click="$set('tab', 'databases')" aria-pressed="{{ $tab === 'databases' ? 'true' : 'false' }}">Databases</button>
        <button type="button" wire:click="$set('tab', 'sql')" aria-pressed="{{ $tab === 'sql' ? 'true' : 'false' }}">SQL</button>
        <button type="button" wire:click="$set('tab', 'search')" aria-pressed="{{ $tab === 'search' ? 'true' : 'false' }}">Search everywhere</button>
        <button type="button" wire:click="$set('tab', 'changes')" aria-pressed="{{ $tab === 'changes' ? 'true' : 'false' }}">Changes</button>
        <button type="button" wire:click="$set('tab', 'encryption')" aria-pressed="{{ $tab === 'encryption' ? 'true' : 'false' }}">Encryption</button>
    </div>

    @if ($error)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">Could not reach MySQL: {{ $error }}</p></div>
    @endif

    @if ($tab === 'databases')
        <x-layer-card title="Databases" helper="{{ count($allDatabases) }} databases · {{ \Illuminate\Support\Number::fileSize($totalSize, precision: 1) }} in all. Sizes are MySQL's own figures and can be a little behind." flush>
            <x-slot:actions>
                <input class="input h-8 w-48" type="search" wire:model.live.debounce.250ms="filter" placeholder="Filter databases" aria-label="Filter databases">
            </x-slot:actions>
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            @foreach (['name' => 'Database', 'tables' => 'Tables', 'size' => 'Size'] as $key => $label)
                                <th>@include('livewire.database.partials.sort-header', ['key' => $key, 'label' => $label])</th>
                            @endforeach
                            <th></th>
                        </tr>
                    </thead>
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
                                        <button class="btn btn-sm" type="button" wire:click="openExport({{ \Illuminate\Support\Js::from($database['name']) }})"><x-menu-icon name="download" class="size-4" /> Export SQL</button>
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
    @elseif ($tab === 'encryption')
        @include('livewire.database.partials.crypto')
    @elseif ($tab === 'sql')
        @include('livewire.database.partials.sql', ['sqlTarget' => $sqlIn, 'sqlChoices' => $allDatabases])
    @elseif ($tab === 'search')
        @include('livewire.database.partials.search', ['scopeChoices' => $allDatabases])
    @else
        <x-layer-card title="Changes" helper="Rows added, edited, and deleted here, and triggers, events, procedures, and functions changed here, newest first. Undo puts back only that row or object; when someone changed the row since, you are asked first." flush>
            @include('livewire.database.partials.changes', ['changes' => $changes])
        </x-layer-card>
    @endif
</div>
