<div class="flex flex-col gap-6">
    <x-page-header title="Links" summary="Shortcuts in the sidebar for the tools you open every day. They open in a new tab.">
        <x-slot:actions>
            <button class="btn" type="button" wire:click="add" @disabled(count($links) >= 15)><x-menu-icon name="plus" /> Add link</button>
            <button class="btn btn-primary" type="submit" form="sidebar-links">Save</button>
        </x-slot:actions>
    </x-page-header>

    <x-layer-card title="phpMyAdmin" helper="Built in. Opens phpMyAdmin already signed in, with a one-time link that works for one minute.">
        @if ($phpMyAdmin)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="flex items-center gap-2 text-[13px]"><span class="status-badge" style="--status-color: var(--color-success)">Ready</span> Shown in the sidebar under Links.</p>
                <a class="btn" href="{{ route('tools.phpmyadmin') }}" target="_blank" rel="noopener"><x-menu-icon name="external" /> Open phpMyAdmin</a>
            </div>
        @else
            <p class="text-[13px] text-muted">Give phpMyAdmin a domain on the <a class="text-accent hover:underline" href="{{ route('server.index') }}" wire:navigate>Server</a> page to show it here.</p>
        @endif
    </x-layer-card>

    <form id="sidebar-links" wire:submit="save">
        <x-layer-card title="Your links" helper="A label and a full address, like https://dash.cloudflare.com." flush>
            @forelse ($links as $index => $link)
                <div class="flex flex-wrap items-start gap-3 border-t border-line px-4 py-3 first:border-t-0" wire:key="link-{{ $index }}">
                    <label class="field w-48">
                        <span class="sr-only">Label</span>
                        <input class="input" wire:model="links.{{ $index }}.label" placeholder="Label" maxlength="40">
                        @error('links.'.$index.'.label') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <label class="field min-w-[16rem] flex-1">
                        <span class="sr-only">Address</span>
                        <input class="input font-mono" wire:model="links.{{ $index }}.url" placeholder="https://" inputmode="url">
                        @error('links.'.$index.'.url') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <div class="flex items-center gap-1 pt-0.5">
                        <button class="icon-btn" type="button" title="Move up" wire:click="move({{ $index }}, -1)" @disabled($loop->first)><x-menu-icon name="up" class="size-4" /></button>
                        <button class="icon-btn" type="button" title="Move down" wire:click="move({{ $index }}, 1)" @disabled($loop->last)><x-menu-icon name="arrow-down" class="size-4" /></button>
                        <button class="icon-btn icon-btn-danger" type="button" title="Remove" wire:click="remove({{ $index }})"><x-menu-icon name="trash" class="size-4" /></button>
                    </div>
                </div>
            @empty
                <p class="table-empty">No links yet. Click Add link.</p>
            @endforelse
        </x-layer-card>
    </form>
</div>
