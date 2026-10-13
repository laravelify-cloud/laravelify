{{-- Browse the folders and files inside a file backup. With $selectable, entries can be ticked for a restore. --}}
@php
    $folder = $archiveFolder;
    $children = $manifest ? \App\Services\BackupInspector::children($manifest, $folder) : [];
    $crumbs = [];
    $prefix = '';

    foreach ($folder === '' ? [] : explode('/', $folder) as $part) {
        $prefix = ltrim($prefix.'/'.$part, '/');
        $crumbs[] = ['label' => $part, 'path' => $prefix];
    }
@endphp

@if ($manifest === null)
    <div class="pick-list">
        <p class="flex items-center justify-center gap-2 bg-card px-3 py-10 text-center text-muted">
            @if (in_array($run->id, $inspecting, true))
                <span class="task-spinner"></span> Reading the backup. This downloads it once; big backups take a moment.
            @else
                The contents list of this backup is not available.
            @endif
        </p>
    </div>
@else
    <div class="grid gap-2">
        <div class="flex flex-wrap items-center gap-1 text-[13px]">
            <button class="fm-crumb" type="button" wire:click="openArchiveFolder('')"><x-menu-icon name="home" class="size-4" /> <span>backup</span></button>
            @foreach ($crumbs as $crumb)
                <x-menu-icon name="chevron-right" class="size-3.5 text-muted" />
                <button class="fm-crumb" type="button" wire:click="openArchiveFolder(@js($crumb['path']))">{{ $crumb['label'] }}</button>
            @endforeach
        </div>

        <div class="pick-list">
            @forelse ($children as $child)
                @php $picked = $selectable && in_array($child['path'], $restoreMembers, true); @endphp
                <div @class(['pick-item', 'is-selected' => $picked]) wire:key="ab-{{ $child['path'] }}">
                    @if ($selectable)
                        <input type="checkbox" @checked($picked) wire:click="toggleMember(@js($child['path']))" aria-label="Select {{ $child['name'] }}">
                    @endif
                    @if ($child['dir'])
                        <button class="flex min-w-0 flex-1 items-center gap-2.5 text-left hover:text-accent" type="button" wire:click="openArchiveFolder(@js($child['path']))">
                            @include('livewire.websites.partials.file-icon', ['kind' => 'folder'])
                            <span class="truncate">{{ $child['name'] }}</span>
                        </button>
                    @else
                        <span class="flex min-w-0 flex-1 items-center gap-2.5">
                            @include('livewire.websites.partials.file-icon', ['kind' => 'file'])
                            <span class="truncate">{{ $child['name'] }}</span>
                        </span>
                        <span class="shrink-0 text-[12px] tabular-nums text-muted">{{ \Illuminate\Support\Number::fileSize($child['size'], precision: 1) }}</span>
                    @endif
                    <span class="hidden shrink-0 text-[12px] text-muted sm:inline">{{ $child['modified'] }}</span>
                </div>
            @empty
                <p class="bg-card px-3 py-6 text-center text-muted">This folder is empty in the backup.</p>
            @endforelse
        </div>
    </div>
@endif
