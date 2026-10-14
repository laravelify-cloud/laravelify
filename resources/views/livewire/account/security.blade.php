{{-- Account security: password and authenticator key. Each change ends with the current authenticator code. --}}
@php
    $time = app(\App\Services\DisplayTime::class);
@endphp

<div
    class="flex flex-col gap-6"
    x-data="{
        otp: { open: false, action: 'password', code: '', busy: false },
        myKey: null,
        ask(action) {
            this.otp = { open: true, action: action, code: '', busy: false };
            $wire.clearCodeError();
            this.$nextTick(() => this.$refs.code && this.$refs.code.focus());
        },
        submit() {
            if (this.otp.code.length < 6 || this.otp.busy) {
                return;
            }

            this.otp.busy = true;
            const call = this.otp.action === 'password' ? $wire.savePassword(this.otp.code) : $wire.saveNewKey(this.otp.code);

            call.then((done) => {
                this.otp.busy = false;

                if (done) {
                    this.otp.open = false;
                    this.myKey = null;
                } else {
                    this.otp.code = '';
                    this.$nextTick(() => this.$refs.code && this.$refs.code.focus());
                }
            });
        },
        async showMyKey() {
            this.myKey = await window.laravelifyRevealSecret($wire, 'two_factor_secret');
        },
    }"
>
    <x-page-header title="Security" summary="Your password and the authenticator app you sign in with. Every change asks for your current authenticator code at the end." />

    {{-- Password --}}
    <x-layer-card title="Password" helper="Use at least 8 characters. A password manager can make a strong one for you.">
        <form class="flex max-w-md flex-col gap-4" @submit.prevent="$wire.checkPassword().then((ok) => ok && ask('password'))">
            <label class="field">
                <span>Current password</span>
                <input class="input" type="password" wire:model="currentPassword" autocomplete="current-password" required>
                @error('currentPassword') <small class="error">{{ $message }}</small> @enderror
            </label>

            <label class="field">
                <span>New password</span>
                <input class="input" type="password" wire:model="password" autocomplete="new-password" minlength="8" required>
                @error('password') <small class="error">{{ $message }}</small> @enderror
            </label>

            <label class="field">
                <span>Repeat the new password</span>
                <input class="input" type="password" wire:model="password_confirmation" autocomplete="new-password" minlength="8" required>
            </label>

            <div>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="checkPassword">
                    <x-menu-icon name="lock" /> Change password
                </button>
            </div>
        </form>
    </x-layer-card>

    {{-- Two-factor authentication --}}
    <x-layer-card title="Two-factor authentication" helper="Sign-in asks for a 6-digit code from your authenticator app. You can see your key to add it to another app, or change it to a new key or a key you already use.">
        <div class="flex flex-col gap-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="stat-icon size-9"><x-menu-icon name="shield" class="size-5 text-success" /></span>
                    <div>
                        <p class="font-medium">Two-factor authentication is on</p>
                        <p class="text-[12.5px] text-muted">
                            @if ($user->two_factor_confirmed_at)
                                Current key set {{ $time->datetime($user->two_factor_confirmed_at) }}
                            @else
                                Current key is set
                            @endif
                        </p>
                    </div>
                </div>

                @if ($keyMode === '')
                    <div class="flex flex-wrap gap-2">
                        <button class="btn" type="button" @click="myKey ? myKey = null : showMyKey()">
                            <x-menu-icon name="eye" x-show="! myKey" />
                            <x-menu-icon name="eye-off" x-show="myKey" x-cloak />
                            <span x-text="myKey ? 'Hide my key' : 'Show my key'"></span>
                        </button>
                        <button class="btn" type="button" wire:click="startNewKey"><x-menu-icon name="refresh" /> New key</button>
                        <button class="btn" type="button" wire:click="startOwnKey"><x-menu-icon name="keys" /> Use my own key</button>
                    </div>
                @endif
            </div>

            {{-- The key in use, after the authenticator code --}}
            <div class="flex max-w-md flex-col gap-1.5" x-cloak x-show="myKey">
                <span class="text-[12.5px] text-muted">Your key. Add it to another authenticator app or password manager to get the same codes there.</span>
                <div class="flex items-center gap-1.5">
                    <code class="input flex-1 truncate font-mono leading-9" x-text="myKey ? myKey.match(/.{1,4}/g).join(' ') : ''"></code>
                    <x-copy-button text="myKey" />
                </div>
            </div>

            {{-- Change the key --}}
            @if ($keyMode !== '')
                <form class="flex max-w-md flex-col gap-4 border-t border-line pt-5" @submit.prevent="$wire.checkNewKey().then((ok) => ok && ask('key'))">
                    <div class="segmented self-start" role="group" aria-label="Which key to use">
                        <button type="button" aria-pressed="{{ $keyMode === 'new' ? 'true' : 'false' }}" wire:click="startNewKey">New key</button>
                        <button type="button" aria-pressed="{{ $keyMode === 'own' ? 'true' : 'false' }}" wire:click="startOwnKey">Use my own key</button>
                    </div>

                    @if ($keyMode === 'new')
                        <p class="text-[13px] text-muted">1. Scan this QR code with your authenticator app, or type the key into it.</p>

                        <div class="flex justify-center self-start rounded-md bg-white p-3 [&_svg]:h-44 [&_svg]:w-44">
                            {!! $newQr !!}
                        </div>

                        <div class="field">
                            <span>New key</span>
                            <div class="flex items-center gap-1.5">
                                <code class="input flex-1 truncate font-mono leading-9" title="{{ $newSecret }}">{{ trim(chunk_split($newSecret, 4, ' ')) }}</code>
                                <x-copy-button :text="'\''.$newSecret.'\''" />
                            </div>
                            <button class="self-start text-[12.5px] text-muted hover:text-ink" type="button" wire:click="startNewKey">
                                <x-menu-icon name="refresh" class="inline size-3.5" /> Make a different key
                            </button>
                        </div>
                    @else
                        <p class="text-[13px] text-muted">1. Paste the key you already use in another app. Your authenticator app keeps working as it is, and the panel uses the same codes.</p>

                        <label class="field">
                            <span>Your key</span>
                            <input class="input font-mono" wire:model="ownSecret" autocomplete="off" spellcheck="false" placeholder="JBSW Y3DP EHPK 3PXP… or otpauth://totp/…" required>
                            <small class="text-muted">Letters A–Z and numbers 2–7, at least {{ \App\Services\TwoFactor::MIN_SECRET_LENGTH }} characters. Spaces do not matter.</small>
                            @error('ownSecret') <small class="error">{{ $message }}</small> @enderror
                        </label>
                    @endif

                    <label class="field">
                        <span>2. Code your app shows for this key</span>
                        <input class="input otp-input max-w-[12rem]" wire:model="newCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required>
                        @error('newCode') <small class="error">{{ $message }}</small> @enderror
                    </label>

                    <x-callout type="warning" title="The old key stops working">
                        After the change, codes from your current key are no longer accepted. Update every app that uses it.
                    </x-callout>

                    <div class="flex gap-2">
                        <button class="btn" type="button" wire:click="cancelKey">Cancel</button>
                        <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="checkNewKey">Continue</button>
                    </div>
                </form>
            @endif
        </div>
    </x-layer-card>

    {{-- Last step of every change: the current authenticator code --}}
    <x-modal-frame class="modal-root-top" open="otp.open" close="otp.open = false">
        <form class="flex flex-col gap-4" @submit.prevent="submit()">
            <div class="modal-head">
                <h2 class="flex items-center gap-2">
                    <x-menu-icon name="lock" class="size-4 text-muted" />
                    <span x-text="otp.action === 'password' ? 'Confirm the new password' : 'Confirm the new key'"></span>
                </h2>
                <button class="icon-btn" type="button" @click="otp.open = false" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>

            <p class="text-[13px] text-muted" x-text="otp.action === 'password'
                ? 'To confirm it is you, enter the current 6-digit code from your authenticator app.'
                : 'Last step: enter the code from the key you use today (not the new one).'"></p>

            <label class="field">
                <span>Authenticator code</span>
                <input class="input otp-input" x-ref="code" x-model="otp.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" @input="otp.code = otp.code.replace(/\D/g, '').slice(0, 6); otp.code.length === 6 && submit()">
                @error('code') <small class="error">{{ $message }}</small> @enderror
            </label>

            <div class="modal-foot">
                <button class="btn" type="button" @click="otp.open = false">Cancel</button>
                <button class="btn btn-primary" type="submit" :disabled="otp.code.length < 6 || otp.busy">
                    <span class="task-spinner" x-show="otp.busy" x-cloak></span>
                    <span x-text="otp.action === 'password' ? 'Change password' : 'Change key'"></span>
                </button>
            </div>
        </form>
    </x-modal-frame>
</div>
