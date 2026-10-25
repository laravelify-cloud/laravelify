<form class="flex flex-col gap-4" wire:submit="save">
    <p class="text-muted">Connect a GitHub App for private repositories, webhooks, and deployments.</p>

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="field">
            <span>Name</span>
            <input class="input" wire:model="name" required>
            @error('name') <small class="error">{{ $message }}</small> @enderror
        </label>
@include('livewire.sources.partials.organization-field')
    </div>

    <p class="text-muted">The App reads your repositories and sends every push to the panel, so sites using it deploy automatically with no repository webhook.</p>

    <div class="overflow-hidden rounded-lg border border-line" x-data="{ open: false }">
        <button class="flex w-full items-center justify-between px-3 py-2 text-left" type="button" @click="open = !open">
            Self-hosted / Enterprise GitHub
            <span x-text="open ? '−' : '+'"></span>
        </button>
        <div class="grid gap-4 border-t border-line p-3 sm:grid-cols-2" x-cloak x-show="open">
            <label class="field">
                <span>HTML URL</span>
                <input class="input" wire:model="html_url">
                @error('html_url') <small class="error">{{ $message }}</small> @enderror
            </label>
            <label class="field">
                <span>API URL</span>
                <input class="input" wire:model="api_url">
                @error('api_url') <small class="error">{{ $message }}</small> @enderror
            </label>
            <label class="field">
                <span>User</span>
                <input class="input" wire:model="git_user">
            </label>
            <label class="field">
                <span>Port</span>
                <input class="input" wire:model="git_port" inputmode="numeric">
            </label>
        </div>
    </div>

    <button class="btn btn-primary w-full" type="submit">Continue</button>
</form>
