<form class="flex flex-col gap-4" wire:submit="confirm">
    <div>
        <h2 class="text-base font-medium">Set up two-factor authentication</h2>
        <p class="mt-1 text-muted">Scan this code with an authenticator app, then enter the 6-digit code. The dashboard stays locked until this is confirmed.</p>
    </div>

    <div class="flex justify-center rounded-md bg-white p-3 [&_svg]:h-48 [&_svg]:w-48">
        {!! $qr !!}
    </div>

    <p class="break-all text-center text-muted">Manual key: {{ $secret }}</p>

    <label class="field">
        <span>Authentication code</span>
        <input class="input" inputmode="numeric" autocomplete="one-time-code" wire:model="code" required>
        @error('code') <small class="error">{{ $message }}</small> @enderror
    </label>

    <button class="btn btn-primary" type="submit">Confirm and open the panel</button>
</form>
