<div class="flex flex-col gap-4">
<form class="card flex flex-col gap-4 p-4" wire:submit="save">
    <label class="field">
        <span>Name</span>
        <input class="input" wire:model="name" required>
        @error('name') <small class="error">{{ $message }}</small> @enderror
    </label>
    <label class="field">
        <span>Replace token</span>
        <textarea class="textarea" style="min-height: 96px" wire:model="token" placeholder="Leave empty to keep the current token"></textarea>
        @error('token') <small class="error">{{ $message }}</small> @enderror
    </label>
    <div class="flex gap-2">
        <button class="btn btn-primary" type="submit">Save tunnel</button>
        <x-confirm-delete
            title="Confirm tunnel deletion?"
            :name="$tunnel->name"
            action="destroy"
            :actions="['This tunnel will be deleted. Websites keep their nginx files and are detached from the tunnel.']"
        />
    </div>
    @error('tunnel') <p class="error">{{ $message }}</p> @enderror
</form>

<section class="card p-4">
    <h2 class="font-medium">Ingress</h2>
    <p class="mt-1 text-muted">Regenerated whenever a website on this tunnel changes. TLS stays at Cloudflare.</p>
    <pre class="textarea mt-3 overflow-auto whitespace-pre-wrap">{{ $tunnel->ingress }}</pre>
</section>

<section class="card overflow-hidden">
    <table class="table">
        <thead>
            <tr>
                <th>Website</th>
                <th>Domain</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($websites as $website)
                <tr>
                    <td><a class="underline" href="{{ route('websites.show', $website) }}" wire:navigate>{{ $website->name }}</a></td>
                    <td>{{ $website->domain }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2" class="text-muted">No websites use this tunnel yet.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</section>
</div>
