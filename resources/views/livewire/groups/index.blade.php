<div class="flex flex-col gap-6">
    <x-page-header title="Groups" summary="A group holds the websites for one product or client.">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('groups.create') }}" wire:navigate><x-menu-icon name="plus" /> New group</a>
        </x-slot:actions>
    </x-page-header>

    <section class="layer-card-body is-flush">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th class="w-40">Websites</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($groups as $group)
                    <tr wire:key="group-{{ $group->id }}">
                        <td>
                            <a class="flex items-center gap-2.5 font-medium hover:text-accent" href="{{ route('groups.show', $group) }}" wire:navigate>
                                <span class="stat-icon size-7"><x-menu-icon name="groups" class="size-4" /></span>
                                {{ $group->name }}
                            </a>
                        </td>
                        <td class="text-muted">{{ $group->websites_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="table-empty">No groups yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
