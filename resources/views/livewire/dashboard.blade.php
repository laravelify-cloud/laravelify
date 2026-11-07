@php
    $stepLinks = [
        route('groups.create'),
        $firstGroup ? route('websites.create', $firstGroup) : route('groups.create'),
        route('groups.index'),
        route('tunnels.index'),
    ];
    $doneSteps = collect($onboarding)->where('done', true)->count();
    $nextStep = collect($onboarding)->search(fn (array $step): bool => ! $step['done']);
    $meters = $server ? [
        ['label' => 'CPU', 'value' => $server->cpu],
        ['label' => 'Memory', 'value' => $server->memory],
        ['label' => 'Disk', 'value' => $server->disk],
    ] : [];
@endphp

<div class="dash flex flex-col gap-6">
    <section class="dash-hero">
        <div class="min-w-0">
            <p class="dash-eyebrow" x-data x-text="(() => { const hour = new Date().getHours(); return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'; })()">Welcome back</p>
            <h2 class="dash-title">{{ auth()->user()->name }}</h2>
            <p class="page-summary">
                @if ($websiteCount === 0)
                    Your panel is ready. Follow the steps below to put your first website online.
                @else
                    {{ $liveWebsiteCount }} of {{ $websiteCount }} {{ Str::plural('website', $websiteCount) }} turned on across {{ $groupCount }} {{ Str::plural('group', $groupCount) }}.
                @endif
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <a class="btn" href="{{ route('deployments.index') }}" wire:navigate><x-menu-icon name="deployments" /> Deployments</a>
            <a class="btn btn-primary" href="{{ route('groups.create') }}" wire:navigate><x-menu-icon name="plus" /> New group</a>
        </div>
    </section>

    @if ($onboarding !== [])
        <section class="card onboarding" aria-labelledby="onboarding-title">
            <div class="onboarding-head">
                <div>
                    <h3 id="onboarding-title">Get started</h3>
                    <p>{{ $doneSteps }} of {{ count($onboarding) }} steps done</p>
                </div>
                <div class="onboarding-progress" role="progressbar" aria-valuemin="0" aria-valuemax="{{ count($onboarding) }}" aria-valuenow="{{ $doneSteps }}" aria-label="Setup progress">
                    <span style="width: {{ round($doneSteps / count($onboarding) * 100) }}%"></span>
                </div>
            </div>
            <ol class="onboarding-steps">
                @foreach ($onboarding as $index => $step)
                    <li @class(['onboarding-step', 'is-done' => $step['done'], 'is-next' => $index === $nextStep])>
                        <span class="onboarding-mark">
                            @if ($step['done'])
                                <x-menu-icon name="check" class="size-4" />
                            @else
                                {{ $index + 1 }}
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="onboarding-title">{{ $step['title'] }}</p>
                            <p class="onboarding-text">{{ $step['text'] }}</p>
                            @if ($index === $nextStep)
                                <a class="btn btn-sm btn-primary onboarding-go" href="{{ $stepLinks[$index] }}" wire:navigate>Start <x-menu-icon name="chevron-right" /></a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <a href="{{ route('groups.index') }}" class="card stat-card" wire:navigate>
            <span class="stat-icon"><x-menu-icon name="groups" /></span>
            <div class="min-w-0 flex-1">
                <p class="stat-label">Groups</p>
                <p class="stat-value">{{ $groupCount }}</p>
                <p class="stat-note">{{ $websiteCount }} {{ Str::plural('website', $websiteCount) }} in all</p>
            </div>
            <x-menu-icon name="arrow-up-right" class="stat-go" />
        </a>
        <a href="{{ route('groups.index') }}" class="card stat-card" wire:navigate>
            <span class="stat-icon"><x-menu-icon name="dashboard" /></span>
            <div class="min-w-0 flex-1">
                <p class="stat-label">Websites</p>
                <p class="stat-value">{{ $websiteCount }}</p>
                <p class="stat-note">{{ $liveWebsiteCount }} turned on</p>
            </div>
            <x-menu-icon name="arrow-up-right" class="stat-go" />
        </a>
        <a href="{{ route('deployments.index') }}" class="card stat-card" wire:navigate>
            <span class="stat-icon"><x-menu-icon name="rocket" /></span>
            <div class="min-w-0 flex-1">
                <p class="stat-label">Deploys · 7 days</p>
                <p class="stat-value">{{ $week['total'] }}</p>
                <p class="stat-note">
                    @if ($week['rate'] === null)
                        No finished deploys yet
                    @else
                        <span @class(['text-success' => $week['failed'] === 0, 'text-warning' => $week['failed'] > 0])>{{ $week['rate'] }}% succeeded</span>
                        @if ($week['failed'] > 0) · {{ $week['failed'] }} failed @endif
                    @endif
                </p>
            </div>
            <x-menu-icon name="arrow-up-right" class="stat-go" />
        </a>
        <a href="{{ route('tunnels.index') }}" class="card stat-card" wire:navigate>
            <span class="stat-icon"><x-menu-icon name="tunnels" /></span>
            <div class="min-w-0 flex-1">
                <p class="stat-label">Tunnels</p>
                <p class="stat-value">{{ $tunnelCount }}</p>
                <p class="stat-note">Cloudflare, no open ports</p>
            </div>
            <x-menu-icon name="arrow-up-right" class="stat-go" />
        </a>
    </div>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <x-layer-card title="Recent deployments" helper="The latest builds across every website." flush>
            <x-slot:actions>
                <a class="btn btn-sm" href="{{ route('deployments.index') }}" wire:navigate>View all <x-menu-icon name="chevron-right" /></a>
            </x-slot:actions>
            @if ($deployments->isEmpty())
                <div class="empty-state">
                    <span class="empty-state-icon"><x-menu-icon name="rocket" /></span>
                    <p class="empty-state-title">No deployments yet</p>
                    <p class="empty-state-text">Create a group, add a website, then deploy it. Every build shows up here with its status.</p>
                    <a class="btn btn-primary mt-2" href="{{ $groupCount > 0 ? route('groups.index') : route('groups.create') }}" wire:navigate>
                        <x-menu-icon name="plus" /> {{ $groupCount > 0 ? 'Open groups' : 'Create a group' }}
                    </a>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Website</th>
                                <th>Status</th>
                                <th>Trigger</th>
                                <th>Duration</th>
                                <th>When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($deployments as $deployment)
                                <tr wire:key="dashboard-deployment-{{ $deployment->id }}">
                                    <td class="max-w-[22rem]">
                                        @if ($deployment->website)
                                            <a class="block truncate font-medium hover:text-accent" href="{{ route('websites.show', $deployment->website) }}?tab=deployments" wire:navigate>{{ $deployment->website->name }}</a>
                                        @else
                                            <span class="text-muted">Removed</span>
                                        @endif
                                        @if ($deployment->commit_message)
                                            <p class="truncate text-[12px] text-muted" title="{{ $deployment->commit_message }}">
                                                @if ($deployment->branch)<span class="font-mono">{{ $deployment->branch }}</span> · @endif{{ Str::limit(strtok($deployment->commit_message, "\n"), 80) }}
                                            </p>
                                        @endif
                                    </td>
                                    <td><x-status-badge :status="$deployment->status" /></td>
                                    <td class="capitalize text-muted">{{ $deployment->trigger }}</td>
                                    <td class="font-mono text-[12.5px] text-muted">{{ $deployment->durationLabel() ?? '—' }}</td>
                                    <td class="whitespace-nowrap text-muted" title="@datetime($deployment->created_at)">@ago($deployment->created_at)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-layer-card>

        <div class="flex flex-col gap-5">
            <x-layer-card title="Server" helper="{{ $server ? 'Last sample '.$server->at->diffForHumans() : 'Live usage of this machine.' }}">
                <x-slot:actions>
                    <a class="icon-btn" href="{{ route('monitoring.server') }}" wire:navigate title="Server monitoring" aria-label="Server monitoring"><x-menu-icon name="arrow-up-right" class="size-4" /></a>
                </x-slot:actions>
                @if ($meters === [])
                    <p class="text-[13px] text-muted">No recent samples. Open <a class="font-medium text-ink hover:text-accent" href="{{ route('monitoring.server') }}" wire:navigate>Server monitoring</a> to start collecting them.</p>
                @else
                    <div class="flex flex-col gap-3.5">
                        @foreach ($meters as $meter)
                            @php($value = $meter['value'])
                            <div @if ($value !== null) style="--meter: {{ min(100, max(0, $value)) }}%" @endif @class(['meter', 'is-warn' => $value >= 75, 'is-danger' => $value >= 90])>
                                <div class="meter-row">
                                    <span>{{ $meter['label'] }}</span>
                                    <span class="font-mono tabular-nums">{{ $value === null ? '—' : number_format($value, 0).'%' }}</span>
                                </div>
                                <div class="meter-track"><span></span></div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-layer-card>

            <section class="quick-tools" aria-label="Tools">
                @foreach ([
                    ['route' => 'files.index', 'icon' => 'files', 'label' => 'Files'],
                    ['route' => 'terminal.index', 'icon' => 'terminal', 'label' => 'Terminal'],
                    ['route' => 'database.index', 'icon' => 'database', 'label' => 'Database'],
                    ['route' => 'backups.databases', 'icon' => 'backup', 'label' => 'Backups'],
                ] as $tool)
                    <a class="quick-tool" href="{{ route($tool['route']) }}" wire:navigate>
                        <x-menu-icon name="{{ $tool['icon'] }}" />
                        <span>{{ $tool['label'] }}</span>
                    </a>
                @endforeach
            </section>
        </div>
    </div>
</div>
