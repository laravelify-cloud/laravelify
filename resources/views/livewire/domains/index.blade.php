<div class="flex flex-col gap-6" wire:poll.30s>
    <x-page-header title="Domains" summary="Every domain and subdomain this panel answers on: the panel, websites and their other domains, previews, and VS Code Live Servers. A website keeps its Live Server name, so each Go Live gets the same address." />

    <div class="flex flex-wrap items-center gap-2">
        <input class="input h-8 w-full max-w-xs text-[13px]" type="search" wire:model.live.debounce.250ms="search" placeholder="Search domains or websites" aria-label="Search domains">
        <select class="select h-8 w-auto text-[13px]" wire:model.live="kind" aria-label="Show">
            <option value="">All kinds</option>
            <option value="panel">Panel</option>
            <option value="website">Websites</option>
            <option value="preview">Previews</option>
            <option value="live">Live Servers</option>
        </select>
        <span class="text-muted text-[13px]">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('domain', count($rows)) }}</span>
    </div>

    <section class="layer-card-body is-flush">
        <table class="table">
            <thead>
                <tr>
                    <th>Domain</th>
                    <th class="w-44">What it is</th>
                    <th>Website</th>
                    <th class="w-36">Status</th>
                    <th class="w-56 text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr wire:key="domain-{{ $row['kind'] }}-{{ $row['host'] }}">
                        <td>
                            <a class="flex items-center gap-2.5 font-medium hover:text-accent" href="https://{{ $row['host'] }}/" target="_blank" rel="noopener">
                                <span class="stat-icon size-7"><x-menu-icon :name="['panel' => 'dashboard', 'live' => 'play', 'preview' => 'eye'][$row['kind']] ?? 'globe'" class="size-4" /></span>
                                {{ $row['host'] }}
                                <x-menu-icon name="external" class="size-3 opacity-50" />
                            </a>
                        </td>
                        <td class="text-muted">{{ $row['label'] }}</td>
                        <td>
                            @if ($row['website'])
                                <a class="hover:text-accent" href="{{ route('websites.show', $row['website']) }}" wire:navigate>{{ $row['website']->name }}</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($row['kind'] === 'live')
                                @if ($row['running'])
                                    <span class="status-badge is-live" style="--status-color: var(--color-success)">Running</span>
                                @else
                                    <span class="status-badge" style="--status-color: var(--color-muted)">Stopped</span>
                                @endif
                            @else
                                <span class="status-badge" style="--status-color: var(--color-success)">Active</span>
                            @endif
                        </td>
                        <td class="text-right">
                            @if ($row['kind'] === 'live')
                                <div class="inline-flex gap-2">
                                    @if ($row['running'])
                                        <button class="btn btn-sm" type="button" wire:click="stopLive({{ $row['website']->id }})" wire:loading.attr="disabled" title="Stop routing the name to the dev server; the website keeps it">
                                            <x-menu-icon name="stop" /> Stop
                                        </button>
                                    @endif
                                    <button class="btn btn-sm" type="button" wire:click="releaseLive({{ $row['website']->id }})" wire:loading.attr="disabled" wire:confirm="Release {{ $row['host'] }}? Its DNS record goes, and the next Go Live gets a new name." title="Remove the name, its tunnel route, and its DNS record">
                                        <x-menu-icon name="trash" /> Release
                                    </button>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="table-empty">No domains match.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</div>
