{{-- Saved passwords and tokens: shown only after an authenticator code (unlocked for a few minutes). --}}
<div x-data>
<template x-teleport="body">
    <div
        x-data
        class="modal-root modal-root-top"
        x-cloak
        x-show="$store.secretGate.open"
        x-transition:enter="modal-fade-in"
        x-transition:enter-start="modal-fade-from"
        x-transition:enter-end="modal-fade-to"
        x-transition:leave="modal-fade-out"
        x-transition:leave-start="modal-fade-to"
        x-transition:leave-end="modal-fade-from"
        @keydown.escape.window="$store.secretGate.open && $store.secretGate.cancel()"
    >
        <div class="modal-backdrop" @click="$store.secretGate.cancel()"></div>
        <form class="card modal-panel" style="width: min(100%, 24rem)" role="dialog" aria-modal="true" aria-labelledby="secret-gate-title" @submit.prevent="$store.secretGate.submit()">
            <div class="modal-head">
                <h2 id="secret-gate-title" class="flex items-center gap-2"><x-menu-icon name="lock" class="size-4 text-muted" /> Confirm it's you</h2>
                <button class="icon-btn" type="button" @click="$store.secretGate.cancel()" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <p class="text-[13px] text-muted">Enter the 6-digit code from your authenticator app to show saved passwords and tokens for {{ \App\Livewire\PanelComponent::SECRET_UNLOCK_MINUTES }} minutes.</p>
            <input
                class="input mt-4 h-11 text-center font-mono text-[20px] tracking-[0.5em]"
                x-ref="code"
                x-model="$store.secretGate.code"
                x-effect="$store.secretGate.open && $nextTick(() => $refs.code.focus())"
                @input="$store.secretGate.code = $event.target.value.replace(/\D/g, '').slice(0, 6); $store.secretGate.code.length === 6 && $store.secretGate.submit()"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                placeholder="000000"
                aria-label="Authenticator code"
            >
            <p class="error mt-2 text-[12.5px]" x-show="$store.secretGate.error" x-text="$store.secretGate.error"></p>
            <div class="modal-foot">
                <button class="btn" type="button" @click="$store.secretGate.cancel()">Cancel</button>
                <button class="btn btn-primary" type="submit" :disabled="$store.secretGate.busy || $store.secretGate.code.length !== 6">
                    <span x-show="!$store.secretGate.busy">Unlock</span>
                    <span x-show="$store.secretGate.busy">Checking…</span>
                </button>
            </div>
        </form>
    </div>
</template>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        Alpine.store('secretGate', {
            open: false,
            code: '',
            error: '',
            busy: false,
            until: @js(((int) session('secrets_unlocked_until', 0)) * 1000),
            wire: null,
            resolve: null,

            unlocked() {
                return Date.now() < this.until;
            },

            /** Resolves true once secrets are unlocked (asking for a code if needed). */
            request(wire) {
                if (this.unlocked()) {
                    return Promise.resolve(true);
                }

                this.wire = wire;
                this.code = '';
                this.error = '';
                this.open = true;

                return new Promise((resolve) => {
                    this.resolve = resolve;
                });
            },

            async submit() {
                if (this.busy || this.code.length !== 6) {
                    return;
                }

                this.busy = true;
                this.error = '';

                try {
                    const result = await this.wire.unlockSecrets(this.code);

                    if (result && result.ok) {
                        this.until = result.until;
                        this.open = false;
                        this.resolve && this.resolve(true);
                    } else {
                        this.error = (result && result.error) || 'Could not check the code.';
                        this.code = '';
                    }
                } finally {
                    this.busy = false;
                }
            },

            cancel() {
                this.open = false;
                this.resolve && this.resolve(false);
            },
        });
    });

    /**
     * Fetch a saved secret of the current Livewire component, asking for the
     * authenticator code first when needed. Resolves to the value or null.
     */
    window.laravelifyRevealSecret = async function (wire, key) {
        const gate = Alpine.store('secretGate');

        for (let attempt = 0; attempt < 2; attempt++) {
            if (!(await gate.request(wire))) {
                return null;
            }

            const result = await wire.revealSecret(key);

            if (result && result.ok) {
                return result.value;
            }

            if (result && result.locked) {
                gate.until = 0;
                continue;
            }

            window.toast((result && result.error) || 'Could not show it.', 'error');

            return null;
        }

        return null;
    };

    /** A password field: the eye shows what is typed, or the saved value after the code. */
    window.secretField = function (options) {
        return {
            shown: false,
            loading: false,

            async toggle() {
                if (this.shown) {
                    this.shown = false;

                    return;
                }

                if (!(await Alpine.store('secretGate').request(this.$wire))) {
                    return;
                }

                if (options.secret && !this.$wire.$get(options.model)) {
                    this.loading = true;
                    const value = await window.laravelifyRevealSecret(this.$wire, options.secret);
                    this.loading = false;

                    if (value === null) {
                        return;
                    }

                    this.$wire.$set(options.model, value, false);
                }

                this.shown = true;
            },
        };
    };

    /** A saved value shown as text (webhook secret, database password). */
    window.secretText = function (key) {
        return {
            value: '',
            shown: false,
            loading: false,

            async load() {
                if (this.value !== '') {
                    return true;
                }

                this.loading = true;
                const value = await window.laravelifyRevealSecret(this.$wire, key);
                this.loading = false;

                if (value === null) {
                    return false;
                }

                this.value = value;

                return true;
            },

            async toggle() {
                if (this.shown) {
                    this.shown = false;

                    return;
                }

                this.shown = await this.load();
            },

            async copy() {
                if (await this.load()) {
                    window.copyToClipboard(this.value, 'Copied to clipboard.');
                }
            },
        };
    };
</script>
