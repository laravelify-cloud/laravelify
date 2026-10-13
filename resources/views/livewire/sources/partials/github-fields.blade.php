<label class="field">
    <span>App name</span>
    <input class="input" wire:model="name" required>
    @error('name') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>Organization</span>
    <input class="input" wire:model="organization" placeholder="Personal account when empty">
    @error('organization') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>HTML URL</span>
    <input class="input" wire:model="html_url" required>
    @error('html_url') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>API URL</span>
    <input class="input" wire:model="api_url" required>
    @error('api_url') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>User</span>
    <input class="input" wire:model="git_user" required>
    @error('git_user') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>Port</span>
    <input class="input" wire:model="git_port" inputmode="numeric" required>
    @error('git_port') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>App ID</span>
    <input class="input" wire:model="app_id" required>
    @error('app_id') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>Installation ID</span>
    <input class="input" wire:model="installation_id" required>
    @error('installation_id') <small class="error">{{ $message }}</small> @enderror
</label>
<x-secret-input label="Client ID" model="client_id" />
<x-secret-input label="Client secret" model="client_secret" />
<label class="field">
    <span>Webhook secret</span>
    <span class="relative block" x-data="{ show: false }">
        <input class="input pr-10" x-bind:type="show ? 'text' : 'password'" wire:model="webhook_secret" autocomplete="new-password">
        <button class="absolute inset-y-0 right-2 text-muted" type="button" @click="show = !show" tabindex="-1" aria-label="Show webhook secret">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
        </button>
    </span>
    @error('webhook_secret') <small class="error">{{ $message }}</small> @enderror
</label>
<label class="field">
    <span>Private key</span>
    <select class="input" wire:model="private_key_id" required>
        <option value="">Select a private key</option>
        @foreach ($privateKeys as $key)
            <option value="{{ $key->id }}">{{ $key->name }}</option>
        @endforeach
    </select>
    <small class="text-muted"><a class="underline" href="{{ route('security.private-keys') }}" wire:navigate>Manage private keys</a></small>
    @error('private_key_id') <small class="error">{{ $message }}</small> @enderror
</label>
