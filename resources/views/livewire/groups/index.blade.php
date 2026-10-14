<div class="flex flex-col gap-6">
    <x-page-header title="Groups" summary="A group holds the websites for one product or client.">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('groups.create') }}" wire:navigate><x-menu-icon name="plus" /> New group</a>
        </x-slot:actions>
    </x-page-header>

    <x-task-tray :tasks="$tasks" :active="$tasksActive" />

    <section
        class="layer-card-body is-flush"
        x-data="{ selected: [], all: @js($groups->pluck('id')->all()), bulkDelete: false, typed: '' }"
        @bulk-done.window="selected = []"
    >
        <div class="fm-selection" x-cloak x-show="selected.length > 0">
            <div class="flex items-center gap-2">
                <button class="icon-btn" type="button" @click="selected = []" aria-label="Clear selection"><x-menu-icon name="close" class="size-4" /></button>
                <span class="font-medium"><span x-text="selected.length"></span> selected</span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <button class="btn btn-sm" type="button" @click="$wire.setEnabled(selected, true)"><x-menu-icon name="play" /> Turn on</button>
                <button class="btn btn-sm" type="button" @click="$wire.setEnabled(selected, false)"><x-menu-icon name="pause" /> Turn off</button>
                <button class="btn btn-sm btn-danger" type="button" @click="bulkDelete = true; typed = ''"><x-menu-icon name="trash" /> Delete</button>
            </div>
        </div>

        <table class="table">
            <thead>
                <tr>
                    <th class="w-10"><input type="checkbox" aria-label="Select all groups" :checked="all.length > 0 && selected.length === all.length" @change="selected = $event.target.checked ? [...all] : []" :disabled="all.length === 0"></th>
                    <th>Name</th>
                    <th class="w-40">Status</th>
                    <th class="w-40">Websites</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($groups as $group)
                    <tr wire:key="group-{{ $group->id }}" :class="selected.includes({{ $group->id }}) && 'is-selected'">
                        <td><input type="checkbox" aria-label="Select {{ $group->name }}" :checked="selected.includes({{ $group->id }})" @change="selected = $event.target.checked ? [...selected, {{ $group->id }}] : selected.filter((id) => id !== {{ $group->id }})"></td>
                        <td>
                            <a class="flex items-center gap-2.5 font-medium hover:text-accent" href="{{ route('groups.show', $group) }}" wire:navigate>
                                <span class="stat-icon size-7"><x-menu-icon name="groups" class="size-4" /></span>
                                {{ $group->name }}
                            </a>
                        </td>
                        <td>
                            @if (in_array($group->id, $switching, true))
                                <span class="status-badge is-switching"><span class="task-spinner"></span> Switching…</span>
                            @elseif (! $group->enabled)
                                <span class="status-badge" style="--status-color: var(--color-muted)">Turned off</span>
                            @elseif ($group->disabled_count > 0)
                                <span class="status-badge" style="--status-color: var(--color-warning)">{{ $group->disabled_count }} off</span>
                            @else
                                <span class="status-badge" style="--status-color: var(--color-success)">On</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $group->websites_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="table-empty">No groups yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <x-bulk-delete-dialog
            action="$wire.deleteMany(selected)"
            what="groups"
            :actions="['Empty groups are deleted.', 'A group that still has websites is kept: delete its websites first.']"
        />
    </section>
</div>
