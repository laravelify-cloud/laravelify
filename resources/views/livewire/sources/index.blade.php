<div class="flex flex-col gap-6">
    <x-page-header title="Sources" :summary="$sources->count().' '.str('Git source')->plural($sources->count()).' connected.'">
        <x-slot:actions>
            <div class="relative" x-data="{ open: false, modal: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button class="btn btn-primary" type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu"><x-menu-icon name="plus" /> New source <x-menu-icon name="chevron-down" class="size-3.5" /></button>
                <div
                    class="dropdown dropdown-right"
                    role="menu"
                    x-cloak
                    x-show="open"
                    x-transition:enter="dropdown-in"
                    x-transition:enter-start="dropdown-from"
                    x-transition:enter-end="dropdown-to"
                    x-transition:leave="dropdown-out"
                    x-transition:leave-start="dropdown-to"
                    x-transition:leave-end="dropdown-from"
                >
                    <button class="dropdown-option" role="menuitem" type="button" @click="modal = true; open = false"><x-menu-icon name="git" /> GitHub App</button>
                </div>
                <template x-teleport="body">
                    <x-modal-frame open="modal" close="modal = false">
                        <div class="modal-head mb-3">
                            <h2>New GitHub App</h2>
                            <button class="icon-btn" type="button" @click="modal = false" aria-label="Close"><x-menu-icon name="close" /></button>
                        </div>
                        <livewire:sources.github-form />
                    </x-modal-frame>
                </template>
            </div>
        </x-slot:actions>
    </x-page-header>

    <section class="layer-card-body is-flush">
        <table class="table">
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Provider</th>
                    <th>Status</th>
                    <th class="text-right"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sources as $source)
                    <tr wire:key="source-{{ $source->id }}">
                        <td>
                            <a class="flex items-center gap-2.5 font-medium hover:text-accent" href="{{ route('sources.github.show', $source) }}" wire:navigate>
                                <span class="stat-icon size-7"><x-menu-icon name="git" class="size-4" /></span>
                                {{ $source->name }}
                            </a>
                        </td>
                        <td class="text-muted">GitHub{{ $source->organization ? ' · '.$source->organization : '' }}</td>
                        <td>
                            <span class="status-badge" style="--status-color: {{ $source->isConnected() ? 'var(--color-success)' : 'var(--color-warning)' }}">{{ $source->isConnected() ? 'Connected' : 'Setup incomplete' }}</span>
                        </td>
                        <td class="w-px whitespace-nowrap">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    class="btn btn-sm"
                                    type="button"
                                    wire:click="reregister({{ $source->id }})"
                                    wire:confirm="Register {{ $source->name }} with GitHub again? Its connection is cleared, and GitHub makes a new App with the permissions the panel asks for now (Contents: read and write). Its {{ $source->websites_count }} {{ str('website')->plural($source->websites_count) }} keep using it once it is installed. Delete the old App on GitHub afterwards."
                                    title="Register this App with GitHub again"
                                ><x-menu-icon name="restore" /> Re-register</button>
                                <x-confirm-delete
                                    title="Delete this GitHub App source?"
                                    :name="$source->name"
                                    :action="'delete('.$source->id.')'"
                                    small
                                    :actions="['The source is removed from the panel. The App on GitHub is not uninstalled.', $source->websites_count.' '.str('website')->plural($source->websites_count).' using it keep their repository, but stop deploying on push until you pick another source in their General tab.']"
                                />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="table-empty">No sources yet. Add a GitHub App to deploy private repositories.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
