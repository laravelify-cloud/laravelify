<form class="flex flex-col gap-5" wire:submit="authenticate">
    <div>
        <h2 class="guest-title">Log in</h2>
        <p class="mt-1.5 text-muted">Superadmin password and authenticator code.</p>
    </div>

    @if (session('status'))
        <p class="callout text-sm" role="status">{{ session('status') }}</p>
    @endif

    <label class="field">
        <span>Email</span>
        <input class="input" type="email" wire:model="email" autocomplete="username" required>
        @error('email') <small class="error">{{ $message }}</small> @enderror
    </label>

    <label class="field">
        <span>Password</span>
        <input class="input" type="password" wire:model="password" autocomplete="current-password" required>
        @error('password') <small class="error">{{ $message }}</small> @enderror
    </label>

    <label class="field">
        <span>Authentication code</span>
        <input class="input" inputmode="numeric" autocomplete="one-time-code" wire:model="code">
        @error('code') <small class="error">{{ $message }}</small> @enderror
    </label>

    <button class="btn btn-primary guest-submit" type="submit" wire:loading.attr="disabled" wire:target="authenticate">Log in</button>
</form>
