<div class="flex flex-col gap-6">
    <x-page-header :title="$group->name" :summary="$group->description ?: 'Add websites of any kind inside this group: backends, front ends, and full-stack apps.'">
        <x-slot:crumbs>
            <a class="transition-colors hover:text-ink" href="{{ route('groups.index') }}" wire:navigate>Groups</a>
        </x-slot:crumbs>
        <x-slot:actions>
            @if ($group->enabled)
                <button class="btn" type="button" wire:click="setGroupEnabled(false)" wire:confirm="Turn off {{ $group->name }}? Every website in it shows a turned-off page and its processes stop until you turn the group on again." @disabled($tasksActive)><x-menu-icon name="pause" /> Turn off group</button>
            @else
                <button class="btn" type="button" wire:click="setGroupEnabled(true)" @disabled($tasksActive)><x-menu-icon name="play" /> Turn on group</button>
            @endif
            <a class="btn" href="{{ route('groups.edit', $group) }}" wire:navigate><x-menu-icon name="pencil" /> Edit</a>
            <x-confirm-delete
                title="Confirm group deletion?"
                :name="$group->name"
                action="delete"
                :actions="['This group will be permanently deleted.']"
            />
            <a class="btn btn-primary" href="{{ route('websites.create', $group) }}" wire:navigate><x-menu-icon name="plus" /> Add website</a>
        </x-slot:actions>
    </x-page-header>
    @error('group') <p class="error">{{ $message }}</p> @enderror

    <x-task-tray :tasks="$tasks" :active="$tasksActive" />

    @unless ($group->enabled)
        <x-callout type="warning" title="This group is turned off">
            Its websites show a turned-off page, their workers are stopped, and deploys wait until you turn the group on again.
        </x-callout>
    @endunless

    <section
        class="layer-card-body is-flush"
        x-data="{ selected: [], all: @js($websites->pluck('id')->all()), bulkDelete: false, typed: '' }"
        @bulk-done.window="selected = []"
    >
        <div class="fm-selection" x-cloak x-show="selected.length > 0">
            <div class="flex items-center gap-2">
                <button class="icon-btn" type="button" @click="selected = []" aria-label="Clear selection"><x-menu-icon name="close" class="size-4" /></button>
                <span class="font-medium"><span x-text="selected.length"></span> selected</span>
            </div>
            <div class="flex flex-wrap items-center gap-1.5">
                <button class="btn btn-sm" type="button" @click="$wire.setWebsitesEnabled(selected, true)" wire:loading.attr="disabled" wire:target="setWebsitesEnabled"><x-menu-icon name="play" /> Turn on</button>
                <button class="btn btn-sm" type="button" @click="$wire.setWebsitesEnabled(selected, false)" wire:loading.attr="disabled" wire:target="setWebsitesEnabled"><x-menu-icon name="pause" /> Turn off</button>
                <button class="btn btn-sm btn-danger" type="button" @click="bulkDelete = true; typed = ''"><x-menu-icon name="trash" /> Delete</button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="table max-sm:table-fixed">
                <thead>
                    <tr>
                        <th class="w-10"><input type="checkbox" aria-label="Select all websites" :checked="all.length > 0 && selected.length === all.length" @change="selected = $event.target.checked ? [...all] : []" :disabled="all.length === 0"></th>
                        <th>Website</th>
                        <th class="max-sm:w-28">Status</th>
                        <th class="max-sm:hidden">Type</th>
                        <th class="max-sm:hidden">Address</th>
                        <th class="max-sm:hidden">Runtime</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($websites as $website)
                        <tr wire:key="website-{{ $website->id }}" :class="selected.includes({{ $website->id }}) && 'is-selected'">
                            <td><input type="checkbox" aria-label="Select {{ $website->name }}" :checked="selected.includes({{ $website->id }})" @change="selected = $event.target.checked ? [...selected, {{ $website->id }}] : selected.filter((id) => id !== {{ $website->id }})"></td>
                            <td>
                                <a @class(['flex items-center gap-2.5 font-medium hover:text-accent', 'opacity-60' => ! $website->enabled, 'env-child' => $website->parent_id]) href="{{ route('websites.show', $website) }}" wire:navigate>
                                    @if ($website->parent_id)
                                        <span class="env-child-line" aria-hidden="true"></span>
                                    @endif
                                    <span class="stat-icon size-7"><x-menu-icon name="{{ $website->definition()['icon'] }}" class="size-4" /></span>
                                    {{ $website->name }}
                                    @if ($website->parent_id || $websites->contains('parent_id', $website->id))
                                        <span class="env-pill max-sm:hidden">{{ $website->environmentName() }} · {{ $website->branch }}</span>
                                    @endif
                                </a>
                                {{-- Phones: the type and address under the name, their columns are hidden. --}}
                                <p class="mt-1 truncate text-[12px] text-muted sm:hidden">{{ $website->typeLabel() }}{{ $website->publicUrl() ? ' · '.preg_replace('#^https?://#', '', $website->publicUrl()) : '' }}</p>
                            </td>
                            <td>
                                @if (in_array($website->id, $deleting, true))
                                    <span class="status-badge is-switching"><span class="task-spinner"></span> Deleting…</span>
                                @elseif (in_array($website->id, $switching, true))
                                    <span class="status-badge is-switching"><span class="task-spinner"></span> Switching…</span>
                                @elseif ($website->enabled)
                                    <span class="status-badge" style="--status-color: var(--color-success)">On</span>
                                @else
                                    <span class="status-badge" style="--status-color: var(--color-muted)">Turned off</span>
                                @endif
                            </td>
                            <td class="max-sm:hidden"><span class="pill">{{ $website->typeLabel() }}</span></td>
                            <td class="max-w-[18rem] truncate text-muted max-sm:hidden">
                                @if ($website->publicUrl())
                                    <a class="hover:text-ink" href="{{ $website->publicUrl() }}" target="_blank" rel="noreferrer">{{ preg_replace('#^https?://#', '', $website->publicUrl()) }}</a>
                                @else
                                    {{ $website->domain ?: '-' }}
                                @endif
                            </td>
                            <td class="text-muted max-sm:hidden">{{ $website->isPhp() ? 'PHP '.$website->php_version : ($website->isNode() ? 'Node '.$website->node_version : ($website->usesNode() ? 'Static (built with Node '.$website->node_version.')' : 'Static files')) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="table-empty">This group has no websites yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-bulk-delete-dialog
            action="$wire.deleteWebsites(selected)"
            what="websites"
            :actions="['Their nginx, worker, and PM2 files are removed and they stop being served.', 'Their deployments, monitoring, and settings are deleted.', 'Their folders move to /var/www/.trash, named with the date.']"
        />
    </section>
</div>
