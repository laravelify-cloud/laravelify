<div class="flex flex-col gap-6">
    <x-page-header title="Workspaces" summary="Projects you open in VS Code. Opening a website's VS Code adds it here; a workspace of several projects opens them all in one editor. Download a workspace as a zip, or share it with a link.">
        <x-slot:actions>
            <button class="btn btn-primary" type="button" wire:click="startCreate"><x-menu-icon name="plus" /> Create workspace</button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex flex-wrap items-center gap-2">
        <input class="input h-8 w-full max-w-xs text-[13px]" type="search" wire:model.live.debounce.250ms="search" placeholder="Search workspaces or projects" aria-label="Search workspaces">
        <span class="text-muted text-[13px]">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('workspace', count($rows)) }}</span>
    </div>

    @if ($rows->isEmpty())
        <section class="ws-empty">
            <span class="ws-icon size-12"><x-menu-icon name="grid" class="size-6" /></span>
            <h3>No workspaces yet</h3>
            <p>Open a website's VS Code and it shows here, or put several projects in one editor.</p>
            <button class="btn btn-primary" type="button" wire:click="startCreate"><x-menu-icon name="plus" /> Create workspace</button>
        </section>
    @else
        <div class="ws-grid">
            @foreach ($rows as $workspace)
                @php $multi = $workspace->websites->count() > 1; @endphp
                <article class="ws-card" wire:key="workspace-{{ $workspace->id }}">
                    <header class="ws-card-head">
                        <span @class(['ws-icon size-10', 'is-multi' => $multi])><x-menu-icon :name="$multi ? 'grid' : 'git'" class="size-5" /></span>
                        <div class="min-w-0 flex-1">
                            <a class="ws-name" href="{{ route('workspaces.open', $workspace) }}" target="_blank" rel="noopener" title="Open {{ $workspace->name }} in VS Code">{{ $workspace->name }}</a>
                            <p class="ws-meta">
                                {{ $workspace->websites->count() }} {{ \Illuminate\Support\Str::plural('project', $workspace->websites->count()) }}
                                · {{ $workspace->opened_at ? 'opened '.$workspace->opened_at->diffForHumans() : 'never opened' }}
                            </p>
                        </div>
                        <button class="icon-btn ws-delete" type="button" wire:click="delete({{ $workspace->id }})" wire:loading.attr="disabled" wire:confirm="Delete the workspace {{ $workspace->name }}? Its projects and their code stay as they are." title="Delete the workspace (the projects stay)" aria-label="Delete {{ $workspace->name }}"><x-menu-icon name="trash" class="size-4" /></button>
                    </header>

                    <ul class="ws-projects">
                        @forelse ($workspace->websites as $website)
                            <li>
                                <x-menu-icon name="git" class="size-4 shrink-0 opacity-60" />
                                <a class="min-w-0 flex-1 truncate hover:text-accent" href="{{ route('websites.show', $website) }}" wire:navigate>{{ $website->name }}</a>
                                <span class="ws-branch">{{ $website->branch }}</span>
                            </li>
                        @empty
                            <li class="text-muted">No projects left: their websites were deleted.</li>
                        @endforelse
                    </ul>

                    @if ($workspace->share_token)
                        <button class="ws-shared" type="button" wire:click="openShare({{ $workspace->id }})" title="Manage the share link">
                            <span class="ws-dot"></span> Shared with a link: anyone who has it can open and change it
                        </button>
                    @endif

                    <footer class="ws-actions">
                        <a class="btn btn-primary ws-open" href="{{ route('workspaces.open', $workspace) }}" target="_blank" rel="noopener"><x-menu-icon name="external" /> Open in VS Code</a>
                        <a class="btn" href="{{ route('workspaces.download', $workspace) }}" title="The projects' code as a zip (no installed packages, builds, or .env files)"><x-menu-icon name="download" /> Zip</a>
                        <button class="btn" type="button" wire:click="openShare({{ $workspace->id }})" title="Share with a link"><x-menu-icon name="link" /> Share</button>
                    </footer>
                </article>
            @endforeach
        </div>
    @endif

    {{-- Create a workspace of several projects. --}}
    <x-modal-frame class="modal-medium" open="$wire.creating" close="$wire.closeCreate()">
        @if ($creating)
            <form class="grid gap-4" wire:submit="create">
                <div class="modal-head">
                    <h2>Create workspace</h2>
                    <button class="icon-btn" type="button" wire:click="closeCreate" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>
                <p class="-mt-2 text-[13px] text-muted">Pick one project, or several to open together in one VS Code. Each keeps its own repository; the workspace's editor can read and change only these projects.</p>

                <label class="fm-search">
                    <x-menu-icon name="search" class="size-4" />
                    <input type="search" wire:model.live.debounce.250ms="projectSearch" placeholder="Search projects" autocomplete="off">
                </label>
                <div class="pick-list" style="max-height: 18rem">
                    @forelse ($projects as $project)
                        @php $website = $project['website']; @endphp
                        @if ($project['reason'] === null || $project['canMove'])
                            <label @class(['pick-item cursor-pointer', 'is-selected' => in_array($website->id, $selected)]) wire:key="pick-{{ $website->id }}">
                                <input type="checkbox" wire:model.live="selected" value="{{ $website->id }}">
                                <x-menu-icon name="git" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ $website->name }}</span>
                                    @if ($project['canMove'])
                                        <span class="block text-[12px] text-muted">Runs as the shared user: it moves to its own user first (its app restarts once).</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-[12px] text-muted">{{ $website->branch }}</span>
                            </label>
                        @else
                            <div class="pick-item opacity-70" wire:key="pick-{{ $website->id }}" title="{{ $project['reason'] }}">
                                <input type="checkbox" disabled aria-label="{{ $website->name }} cannot be picked">
                                <x-menu-icon name="git" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">{{ $website->name }}</span>
                                    <span class="block text-[12px] text-muted">{{ $project['reason'] }}</span>
                                </span>
                            </div>
                        @endif
                    @empty
                        <p class="bg-card px-3 py-6 text-center text-muted">No websites match.</p>
                    @endforelse
                </div>
                <small class="text-muted">{{ count($selected) }} selected</small>
                @error('selected') <small class="error">{{ $message }}</small> @enderror

                <label class="grid gap-1.5">
                    <span class="text-[13px] font-medium">Name</span>
                    <input class="input" type="text" wire:model="name" maxlength="80" placeholder="{{ $suggested ?: 'Named after its projects' }}">
                    @error('name') <small class="error">{{ $message }}</small> @enderror
                </label>

                <div class="modal-foot">
                    <button class="btn" type="button" wire:click="closeCreate">Cancel</button>
                    <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="create"><x-menu-icon name="plus" /> Create workspace</button>
                </div>
            </form>
        @endif
    </x-modal-frame>

    {{-- Share a workspace with a link. --}}
    <x-modal-frame class="modal-medium" open="$wire.sharing !== null" close="$wire.closeShare()">
        @if ($shared)
            <div class="grid gap-4">
                <div class="modal-head">
                    <h2>Share {{ $shared->name }}</h2>
                    <button class="icon-btn" type="button" wire:click="closeShare" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>

                @if ($shareUrl)
                    <p class="-mt-2 text-[13px] text-muted">Anyone with this link opens the workspace in VS Code without a panel account, and can change, commit, and push its code. Turn the link off to stop it working; a new link replaces the old one.</p>
                    <div class="flex items-center gap-2">
                        <input class="input font-mono text-[12.5px]" type="text" value="{{ $shareUrl }}" readonly onclick="this.select()" aria-label="Share link">
                        <x-copy-button :text="json_encode($shareUrl)" />
                    </div>
                    <div class="modal-foot">
                        <button class="btn" type="button" wire:click="share({{ $shared->id }})" wire:confirm="Make a new link? The current link stops working.">New link</button>
                        <button class="btn btn-danger" type="button" wire:click="unshare({{ $shared->id }})">Turn off link</button>
                    </div>
                @else
                    <p class="-mt-2 text-[13px] text-muted">Make a link that opens this workspace in VS Code for anyone who has it, without a panel account. They can change, commit, and push its code, so share it only with people you trust. You can turn it off any time.</p>
                    <div class="modal-foot">
                        <button class="btn" type="button" wire:click="closeShare">Cancel</button>
                        <button class="btn btn-primary" type="button" wire:click="share({{ $shared->id }})"><x-menu-icon name="link" /> Make share link</button>
                    </div>
                @endif
            </div>
        @endif
    </x-modal-frame>
</div>
