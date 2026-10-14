<form class="flex flex-col gap-4" wire:submit="confirm">
    <div>
        <h2 class="text-base font-medium">Set up two-factor authentication</h2>
        <p class="mt-1 text-muted">The panel asks for a 6-digit code from an authenticator app at every sign-in. The dashboard stays locked until this is confirmed.</p>
    </div>

    <div class="segmented self-start" role="group" aria-label="Which key to use">
        <button type="button" aria-pressed="{{ $mode === 'new' ? 'true' : 'false' }}" wire:click="useNewKey">New key</button>
        <button type="button" aria-pressed="{{ $mode === 'own' ? 'true' : 'false' }}" wire:click="useOwnKey">Use my own key</button>
    </div>

    @if ($mode === 'new')
        <p class="text-[13px] text-muted">Scan this QR code with your authenticator app (Google Authenticator, Authy, 1Password, Bitwarden…), or type the key into it.</p>

        <div class="flex justify-center rounded-md bg-white p-3 [&_svg]:h-48 [&_svg]:w-48">
            {!! $qr !!}
        </div>

        <div class="field">
            <span>Key</span>
            <div class="flex items-center gap-1.5">
                <code class="input flex-1 truncate font-mono leading-9" title="{{ $secret }}">{{ trim(chunk_split($secret, 4, ' ')) }}</code>
                <x-copy-button :text="'\''.$secret.'\''" />
            </div>
            <button class="self-start text-[12.5px] text-muted hover:text-ink" type="button" wire:click="useNewKey(true)">
                <x-menu-icon name="refresh" class="inline size-3.5" /> Make a different key
            </button>
        </div>
    @else
        <p class="text-[13px] text-muted">Already use an authenticator key somewhere else? Paste it here and the panel uses the same codes. Your app keeps working as it is.</p>

        <label class="field">
            <span>Your key</span>
            <input class="input font-mono" wire:model="ownSecret" autocomplete="off" spellcheck="false" placeholder="JBSW Y3DP EHPK 3PXP… or otpauth://totp/…" required>
            <small class="text-muted">Letters A–Z and numbers 2–7, at least {{ \App\Services\TwoFactor::MIN_SECRET_LENGTH }} characters. Spaces do not matter.</small>
            @error('ownSecret') <small class="error">{{ $message }}</small> @enderror
        </label>
    @endif

    <label class="field">
        <span>Code from your app</span>
        <input class="input otp-input" inputmode="numeric" autocomplete="one-time-code" maxlength="8" placeholder="000000" wire:model="code" required>
        @error('code') <small class="error">{{ $message }}</small> @enderror
    </label>

    <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="confirm">Confirm and open the panel</button>
</form>
