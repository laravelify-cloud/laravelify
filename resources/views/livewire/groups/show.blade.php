<div class="flex flex-col gap-6">
    <x-page-header :title="$group->name" :summary="$group->description ?: 'Add Laravel and Next.js websites inside this group.'">
        <x-slot:crumbs>
            <a class="transition-colors hover:text-ink" href="{{ route('groups.index') }}" wire:navigate>Groups</a>
        </x-slot:crumbs>
        <x-slot:actions>
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

    <section class="layer-card-body is-flush">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Website</th>
                        <th>Type</th>
                        <th>Address</th>
                        <th>Runtime</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($websites as $website)
                        <tr wire:key="website-{{ $website->id }}">
                            <td>
                                <a class="flex items-center gap-2.5 font-medium hover:text-accent" href="{{ route('websites.show', $website) }}" wire:navigate>
                                    <span class="stat-icon size-7"><x-menu-icon name="{{ $website->definition()['icon'] }}" class="size-4" /></span>
                                    {{ $website->name }}
                                </a>
                            </td>
                            <td><span class="pill">{{ $website->typeLabel() }}</span></td>
                            <td class="max-w-[18rem] truncate text-muted">
                                @if ($website->publicUrl())
                                    <a class="hover:text-ink" href="{{ $website->publicUrl() }}" target="_blank" rel="noreferrer">{{ preg_replace('#^https?://#', '', $website->publicUrl()) }}</a>
                                @else
                                    {{ $website->domain ?: '-' }}
                                @endif
                            </td>
                            <td class="text-muted">{{ $website->isPhp() ? 'PHP '.$website->php_version : ($website->isNode() ? 'Node '.$website->node_version : ($website->usesNode() ? 'Static (built with Node '.$website->node_version.')' : 'Static files')) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="table-empty">This group has no websites yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
