<form class="flex flex-col gap-5" wire:submit="confirm">
    <div>
        <h2 class="guest-title">Opened from Laravelify Cloud</h2>
        <p class="mt-1.5 text-muted">Laravelify Cloud confirmed it is you. Enter the code from your authenticator app to finish signing in.</p>
    </div>

    <label class="field">
        <span>Authentication code</span>
        <input class="input" inputmode="numeric" autocomplete="one-time-code" wire:model="code" autofocus required>
        @error('code') <small class="error">{{ $message }}</small> @enderror
    </label>

    <button class="btn btn-primary guest-submit" type="submit" wire:loading.attr="disabled" wire:target="confirm">Sign in</button>

    <a class="text-center text-muted text-sm" href="{{ route('login') }}" wire:navigate>Sign in with your password instead</a>
</form>
