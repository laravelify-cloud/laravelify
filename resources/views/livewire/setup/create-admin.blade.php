{{-- One root element. @unless as the first line prints an HTML comment on the same
     line as <form>, and Livewire then binds only the heading, so Continue reloads the page. --}}
<div>
    @unless ($allowed)
        <div class="flex flex-col items-start gap-4">
            <span class="guest-icon-badge">
                <x-menu-icon name="lock" class="size-5" />
            </span>
            <div>
                <h2 class="guest-title">Open the setup link from the installer</h2>
                <p class="mt-1.5 text-muted">To keep anyone else from creating the superadmin, setup needs the link the installer printed at the end, like <code>https://your-panel/setup?token=...</code>. It is also in <code>/opt/laravelify/.env</code> as <code>LARAVELIFY_SETUP_TOKEN</code>.</p>
            </div>
        </div>
    @else
        <form
            class="flex flex-col gap-5"
            wire:submit="save"
            x-data="{
                shown: false,
                password: '',
                confirmation: '',
                get score() {
                    const value = this.password;
                    if (value.length < 8) { return value.length ? 1 : 0; }
                    let score = 1;
                    if (value.length >= 12) { score++; }
                    if (/[a-z]/.test(value) && /[A-Z]/.test(value)) { score++; }
                    if (/\d/.test(value) && /[^A-Za-z0-9]/.test(value)) { score++; }
                    return score;
                },
                get strengthLabel() {
                    if (this.password.length < 8) { return 'At least 8 characters'; }
                    return ['', 'Weak', 'Fair', 'Good', 'Strong'][this.score];
                },
            }"
        >
            <x-setup-steps :current="1" />

            <div>
                <h2 class="guest-title">Create the superadmin</h2>
                <p class="mt-1.5 text-muted">This panel has a single account. Choose the details you will sign in with.</p>
            </div>

            <div class="flex flex-col gap-4">
                <label class="field">
                    <span>Name</span>
                    <input class="input" type="text" wire:model="name" autocomplete="name" placeholder="Jane Doe" autofocus required>
                    @error('name') <small class="error">{{ $message }}</small> @enderror
                </label>

                <label class="field">
                    <span>Email</span>
                    <input class="input" type="email" wire:model="email" autocomplete="username" placeholder="you@example.com" required>
                    @error('email') <small class="error">{{ $message }}</small> @enderror
                </label>

                <div class="field">
                    <label for="setup-password">Password</label>
                    <span class="guest-reveal">
                        <input id="setup-password" class="input" :type="shown ? 'text' : 'password'" type="password" wire:model="password" x-on:input="password = $event.target.value" autocomplete="new-password" required>
                        <button type="button" class="guest-reveal-btn" x-on:click="shown = ! shown" :aria-label="shown ? 'Hide passwords' : 'Show passwords'" :title="shown ? 'Hide passwords' : 'Show passwords'">
                            <x-menu-icon name="eye" class="size-4" x-show="! shown" />
                            <x-menu-icon name="eye-off" class="size-4" x-cloak x-show="shown" />
                        </button>
                    </span>
                    <span class="guest-strength" :data-level="score" aria-live="polite">
                        <span class="guest-strength-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                        <span class="guest-strength-label" x-text="strengthLabel">At least 8 characters</span>
                    </span>
                    @error('password') <small class="error">{{ $message }}</small> @enderror
                </div>

                <div class="field">
                    <label for="setup-password-confirmation">Confirm password</label>
                    <input id="setup-password-confirmation" class="input" :type="shown ? 'text' : 'password'" type="password" wire:model="password_confirmation" x-on:input="confirmation = $event.target.value" autocomplete="new-password" required>
                    <small class="guest-match" x-cloak x-show="confirmation !== ''" :class="confirmation === password ? 'is-match' : ''" aria-live="polite">
                        <x-menu-icon name="check" class="size-3.5" x-show="confirmation === password" />
                        <span x-text="confirmation === password ? 'Passwords match' : 'Passwords do not match yet'"></span>
                    </small>
                </div>
            </div>

            <button class="btn btn-primary guest-submit" type="submit" wire:loading.attr="disabled" wire:target="save">
                <span class="flex items-center gap-2" wire:loading.remove wire:target="save">
                    Create account and continue
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6" /></svg>
                </span>
                <span class="items-center gap-2" wire:loading.flex wire:target="save">
                    <span class="loader-spinner guest-spinner"></span>
                    Creating the account…
                </span>
            </button>

            <p class="guest-note">
                <x-menu-icon name="shield" class="size-4 shrink-0" />
                <span>Next you will link an authenticator app. The panel asks for its code at every sign-in.</span>
            </p>
        </form>
    @endunless
</div>
