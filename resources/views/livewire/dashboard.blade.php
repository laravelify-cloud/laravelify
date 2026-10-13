<div class="flex flex-col gap-6">
    <div class="grid gap-3 sm:grid-cols-3">
        <a href="{{ route('groups.index') }}" class="card stat-card" wire:navigate>
            <span class="stat-icon"><x-menu-icon name="groups" /></span>
            <div>
                <p class="text-[13px] text-muted">Groups</p>
                <p class="stat-value">{{ $groupCount }}</p>
            </div>
        </a>
        <a href="{{ route('groups.index') }}" class="card stat-card" wire:navigate>
            <span class="stat-icon"><x-menu-icon name="dashboard" /></span>
            <div>
                <p class="text-[13px] text-muted">Websites</p>
                <p class="stat-value">{{ $websiteCount }}</p>
            </div>
        </a>
        <a href="{{ route('tunnels.index') }}" class="card stat-card" wire:navigate>
            <span class="stat-icon"><x-menu-icon name="tunnels" /></span>
            <div>
                <p class="text-[13px] text-muted">Tunnels</p>
                <p class="stat-value">{{ $tunnelCount }}</p>
            </div>
        </a>
    </div>

    <x-layer-card title="Recent deployments" helper="The latest builds across every website." flush>
        <x-slot:actions>
            <a class="btn" href="{{ route('groups.create') }}" wire:navigate><x-menu-icon name="plus" /> New group</a>
        </x-slot:actions>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Website</th>
                        <th>Status</th>
                        <th>Trigger</th>
                        <th>When</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($deployments as $deployment)
                        <tr wire:key="dashboard-deployment-{{ $deployment->id }}">
                            <td>
                                @if ($deployment->website)
                                    <a class="font-medium hover:text-accent" href="{{ route('websites.show', $deployment->website) }}?tab=deployments" wire:navigate>{{ $deployment->website->name }}</a>
                                @else
                                    <span class="text-muted">Removed</span>
                                @endif
                            </td>
                            <td><x-status-badge :status="$deployment->status" /></td>
                            <td class="capitalize text-muted">{{ $deployment->trigger }}</td>
                            <td class="text-muted" title="@datetime($deployment->created_at)">@ago($deployment->created_at)</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="table-empty">No deployments yet. Create a group, add a website, then deploy it.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-layer-card>
</div>
