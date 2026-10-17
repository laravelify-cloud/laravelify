<div class="flex flex-col gap-6" wire:poll.4s.visible>
    <x-page-header title="Deployments" summary="Every website and how its latest deployment went. Open a card to watch or read the live log.">
        <x-slot:actions>
            @if ($running > 0)
                <span class="status-badge is-live" style="--status-color: var(--color-warning)">{{ $running }} in progress</span>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-3">
        <input class="input h-9 w-64" type="search" wire:model.live.debounce.300ms="search" placeholder="Find a website" aria-label="Find a website">
        <div class="segmented">
            @foreach (['all' => 'All', 'running' => 'In progress', 'failed' => 'Failed', 'success' => 'Finished'] as $key => $label)
                <button type="button" wire:click="$set('filter', '{{ $key }}')" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    @if ($cards->isEmpty())
        <div class="card p-8 text-center text-[13px] text-muted">
            {{ $search !== '' || $filter !== 'all' ? 'No website matches.' : 'No websites yet. Add one in a group and its deployments show here.' }}
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($cards as $card)
                @php
                    $website = $card['website'];
                    $deployment = $card['deployment'];
                    $live = $deployment && in_array($deployment->status, ['queued', 'running'], true);
                @endphp
                <div class="card flex flex-col gap-3 p-4" wire:key="deployment-card-{{ $website->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a class="block truncate text-[15px] font-semibold hover:text-accent" href="{{ route('websites.show', $website) }}?tab=deployments" wire:navigate>{{ $website->name }}</a>
                            <p class="truncate text-[12px] text-muted">{{ $website->group?->name }}{{ $website->typeLabel() ? ' · '.$website->typeLabel() : '' }}</p>
                        </div>
                        @if ($deployment)
                            <x-status-badge :status="$deployment->status" />
                        @else
                            <span class="status-badge" style="--status-color: var(--color-muted)">Never deployed</span>
                        @endif
                    </div>

                    @if ($deployment)
                        <div class="min-w-0 text-[13px]">
                            <p class="flex items-center gap-2">
                                @if ($deployment->commit_sha)
                                    <code class="shrink-0">{{ substr($deployment->commit_sha, 0, 7) }}</code>
                                @endif
                                <span class="truncate" title="{{ $deployment->commit_message }}">{{ $deployment->commit_message ? strtok($deployment->commit_message, "\n") : 'No commit message' }}</span>
                            </p>
                            <p class="mt-1 truncate text-[12px] text-muted">
                                {{ collect([$deployment->commit_author, $deployment->branch ? 'on '.$deployment->branch : null, ucfirst($deployment->trigger)])->filter()->implode(' · ') }}
                            </p>
                        </div>
                        <p class="flex items-center gap-3 text-[12px] text-muted">
                            <span title="@datetime($deployment->created_at)">@ago($deployment->created_at)</span>
                            @if ($deployment->durationLabel())
                                <span class="tabular-nums">{{ $live ? 'running ' : 'took ' }}{{ $deployment->durationLabel() }}</span>
                            @endif
                        </p>
                    @else
                        <p class="text-[13px] text-muted">This website has not been deployed yet.</p>
                    @endif

                    <div class="mt-auto flex items-center gap-2 pt-1">
                        <a class="btn btn-sm flex-1" href="{{ route('websites.show', $website) }}?tab=deployments" wire:navigate><x-menu-icon name="terminal" class="size-3.5" /> {{ $live ? 'Watch live' : 'View log' }}</a>
                        <button class="btn btn-sm btn-primary" type="button" wire:click="deploy({{ $website->id }})" wire:loading.attr="disabled" wire:target="deploy({{ $website->id }})" @disabled($live)><x-menu-icon name="rocket" class="size-3.5" /> Deploy</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
