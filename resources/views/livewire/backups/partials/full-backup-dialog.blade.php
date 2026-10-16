{{-- Full backup of every database: each in its own dump, with the tables to leave out. --}}
<x-modal-frame class="modal-wide" open="$wire.fullOpen" close="$wire.set('fullOpen', false)">
    @if ($fullOpen)
        <div class="grid gap-4">
            <div class="modal-head">
                <div>
                    <h2>Full backup</h2>
                    <p class="text-[12.5px] text-muted">Every database is dumped on its own, one file each. Untick the tables you do not need (cache, sessions, logs, data that never changes); they stay unticked for the next full backup.</p>
                </div>
                <button class="icon-btn" type="button" wire:click="$set('fullOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>

            @if ($fullDatabases === [])
                <p class="text-[13px] text-muted">No databases found on this server.</p>
            @else
                @include('livewire.backups.partials.table-picker', ['databases' => $fullDatabases, 'model' => 'fullSkip'])
            @endif

            <div class="flex justify-end gap-2 border-t border-line pt-4">
                <button class="btn" type="button" wire:click="$set('fullOpen', false)">Cancel</button>
                <button class="btn btn-primary" type="button" wire:click="startFullBackup" wire:loading.attr="disabled" wire:target="startFullBackup" @disabled($fullDatabases === [])>
                    <x-menu-icon name="backup" /> Back up {{ count($fullDatabases) }} {{ count($fullDatabases) === 1 ? 'database' : 'databases' }}
                </button>
            </div>
        </div>
    @endif
</x-modal-frame>
