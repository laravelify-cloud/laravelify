<div class="flex flex-col gap-6">
    <x-page-header title="Tunnels" summary="Cloudflare tunnels are separate from websites. Each website picks one tunnel, and one tunnel can serve many hostnames.">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('tunnels.create') }}" wire:navigate><x-menu-icon name="plus" /> New tunnel</a>
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
                @forelse ($tunnels as $tunnel)
                    <tr wire:key="tunnel-{{ $tunnel->id }}">
                        <td>
                            <a class="flex items-center gap-2.5 font-medium hover:text-accent" href="{{ route('tunnels.show', $tunnel) }}" wire:navigate>
                                <span class="stat-icon size-7"><x-menu-icon name="tunnels" class="size-4" /></span>
                                {{ $tunnel->name }}
                            </a>
                        </td>
                        <td class="text-muted">{{ $tunnel->websites_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2" class="table-empty">No tunnels yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
