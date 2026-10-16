{{-- One backup or restore in the list. Older runs of the same database hang under the newest. --}}
<tr
    wire:key="run-{{ $run->id }}-{{ $run->status }}"
    @if ($nested) class="run-older" x-show="{{ $show ?? 'open' }}" x-cloak @endif
    :class="selected.includes({{ $run->id }}) && 'is-selected'"
>
    <td>
        @if ($run->restorable())
            <input type="checkbox" aria-label="Select backup {{ $run->id }}" :checked="selected.includes({{ $run->id }})" @change="selected = $event.target.checked ? [...selected, {{ $run->id }}] : selected.filter((id) => id !== {{ $run->id }})">
        @endif
    </td>
    <td class="max-w-xs">
        <span class="flex items-center gap-2">
            <x-menu-icon name="{{ $run->isBackup() ? ($run->isDatabase() ? 'database' : 'backup') : 'restore' }}" class="size-4 shrink-0 text-muted" />
            <span class="truncate font-mono text-[13px]" title="{{ $run->target }}">{{ $run->target }}</span>
        </span>
        <span class="block truncate text-[12px] text-muted">
            @if ($older > 0)
                <button class="run-older-toggle" type="button" @click="open = ! open" :aria-expanded="open">
                    <x-menu-icon name="chevron-right" class="size-3.5" ::class="open && 'rotate-90'" />
                    <span x-text="open ? 'Hide older' : '{{ $older }} older {{ $older === 1 ? 'backup' : 'backups' }}'"></span>
                </button>
                ·
            @endif
            @if ($run->isBackup())
                {{ $run->schedule?->name ?? 'Deleted schedule' }} · {{ $run->trigger === 'manual' ? 'Run now' : 'Scheduled' }}
            @else
                Restore{{ ! empty($run->meta['tables']) ? ' of '.count($run->meta['tables']).' table(s)' : '' }}{{ ! empty($run->meta['database']) && $run->meta['database'] !== $run->target ? ' into '.$run->meta['database'] : '' }}
            @endif
        </span>
    </td>
    <td>
        @if ($run->status === 'running')
            <div class="min-w-[12rem] max-w-[16rem]">
                <div class="flex items-center gap-2 text-[12.5px]">
                    <span class="task-spinner shrink-0"></span>
                    <span class="truncate">{{ $run->phase ?: 'Working' }}</span>
                    @if ($run->progress !== null)
                        <span class="ml-auto font-medium tabular-nums">{{ $run->progress }}%</span>
                    @endif
                </div>
                <div @class(['task-bar', 'is-waiting' => $run->progress === null])><span style="width: {{ max(3, (int) $run->progress) }}%"></span></div>
            </div>
        @else
            <x-status-badge :status="$run->status" />
            @if ($run->isStuck())
                <span class="block max-w-[14rem] text-[11.5px] text-muted">Waiting for the queue worker</span>
            @endif
        @endif
    </td>
    <td class="text-muted">{{ $run->isBackup() ? $run->sizeLabel() : '—' }}</td>
    <td class="text-muted">
        {{ $time->datetime($run->created_at) }}
        @if ($run->durationLabel())
            <span class="block text-[12px]">{{ $run->durationLabel() }}</span>
        @endif
    </td>
    <td>
        <div class="row-actions w-full">
            @if ($run->restorable())
                <button class="icon-btn" type="button" wire:click="openView({{ $run->id }})" title="Look inside" aria-label="Look inside"><x-menu-icon name="eye" /></button>
                <button class="icon-btn" type="button" wire:click="openDownload({{ $run->id }})" title="Download" aria-label="Download"><x-menu-icon name="download" /></button>
                <button class="icon-btn is-accent" type="button" wire:click="openRestore({{ $run->id }})" title="Restore" aria-label="Restore"><x-menu-icon name="restore" /></button>
            @endif
            @include('livewire.backups.partials.row-menu', ['items' => array_filter([
                ['label' => 'Show log', 'icon' => 'logs', 'click' => 'showLog('.$run->id.')'],
                $run->restorable() ? ['label' => 'Delete backup', 'icon' => 'trash', 'click' => 'deleteBackup('.$run->id.')', 'danger' => true, 'confirm' => 'Delete this backup from the bucket? This cannot be undone.'] : null,
            ])])
        </div>
    </td>
</tr>
