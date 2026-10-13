<form class="card flex flex-col gap-4 p-4" wire:submit="save">
    <p class="text-muted">Paste the Cloudflare tunnel token from Zero Trust. Laravelify starts one cloudflared service for this tunnel and writes ingress from the websites that select it. Each hostname is sent to nginx on 127.0.0.1:80.</p>
    <label class="field">
        <span>Name</span>
        <input class="input" wire:model="name" required>
        @error('name') <small class="error">{{ $message }}</small> @enderror
    </label>
    <label class="field">
        <span>Tunnel token</span>
        <textarea class="textarea" style="min-height: 120px" wire:model="token" required></textarea>
        @error('token') <small class="error">{{ $message }}</small> @enderror
    </label>
    <div class="flex gap-2">
        <button class="btn btn-primary" type="submit">Save tunnel</button>
        <a class="btn" href="{{ route('tunnels.index') }}" wire:navigate>Cancel</a>
    </div>
</form>
