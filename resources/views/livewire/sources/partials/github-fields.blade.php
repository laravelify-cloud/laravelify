<label class="field">
    <span>App name</span>
    <input class="input" wire:model="name" required>
    @error('name') <small class="error">{{ $message }}</small> @enderror
</label>
@include('livewire.sources.partials.organization-field')
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
<x-secret-input label="Client secret" model="client_secret" secret="client_secret" :saved="isset($githubApp) && filled($githubApp->client_secret)" />
<x-secret-input label="Webhook secret" model="webhook_secret" secret="webhook_secret" :saved="isset($githubApp) && filled($githubApp->webhook_secret)" />
<label class="field">
    <span>Private key</span>
    <select class="select" wire:model="private_key_id" required>
        <option value="">Select a private key</option>
        @foreach ($privateKeys as $key)
            <option value="{{ $key->id }}">{{ $key->name }}</option>
        @endforeach
    </select>
    <small class="text-muted"><a class="underline" href="{{ route('security.private-keys') }}" wire:navigate>Manage private keys</a></small>
    @error('private_key_id') <small class="error">{{ $message }}</small> @enderror
</label>
