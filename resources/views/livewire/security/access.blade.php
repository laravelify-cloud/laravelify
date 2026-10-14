{{-- Security → Access: the browser sign-in prompt in front of the panel and phpMyAdmin, and the IPs that skip it. --}}
<div
    class="flex flex-col gap-6"
    x-data="{
        otp: { open: false, code: '', busy: false },
        ask() {
            this.otp = { open: true, code: '', busy: false };
            $wire.clearCodeError();
            this.$nextTick(() => this.$refs.code && this.$refs.code.focus());
        },
        submit() {
            if (this.otp.code.length < 6 || this.otp.busy) {
                return;
            }

            this.otp.busy = true;
            $wire.save(this.otp.code).then((done) => {
                this.otp.busy = false;

                if (done) {
                    this.otp.open = false;
                } else {
                    this.otp.code = '';
                    this.$nextTick(() => this.$refs.code && this.$refs.code.focus());
                }
            });
        },
    }"
>
    <x-page-header title="Access" summary="Put a browser sign-in prompt in front of the panel and phpMyAdmin, before anything else loads. Bots and scanners never even see the login page." />

    <form class="flex flex-col gap-6" @submit.prevent="$wire.check().then((ok) => ok && ask())">
        @foreach (['panel' => 'Panel', 'phpmyadmin' => 'phpMyAdmin'] as $target => $title)
            @php
                $domain = $domains[$target];
            @endphp

            <x-layer-card :title="$title" :helper="$domain ? 'https://'.$domain : 'phpMyAdmin is not set up on this server.'" wire:key="target-{{ $target }}">
                <div class="flex flex-col gap-4" x-data="{ get method() { return $wire.targets['{{ $target }}'].method }, get enabled() { return $wire.targets['{{ $target }}'].enabled } }">
                    <label class="flex items-center justify-between gap-4 rounded-lg border border-line bg-recessed px-3 py-2.5">
                        <span>
                            <span class="block text-[13.5px] font-medium">Ask before opening {{ $title }}</span>
                            <span class="block text-[12px] text-muted">The browser shows a sign-in box. Allowed IPs below skip it.</span>
                        </span>
                        <input type="checkbox" wire:model.live="targets.{{ $target }}.enabled" @disabled(! $domain)>
                    </label>

                    <div class="flex flex-col gap-4" x-show="enabled" x-cloak>
                        <div class="field">
                            <span>What the sign-in box asks for</span>
                            <div class="grid gap-2 sm:grid-cols-3">
                                @foreach ($methods as $value => [$label, $hint])
                                    <label class="flex cursor-pointer gap-2.5 rounded-lg border border-line px-3 py-2.5 has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                                        <input class="mt-0.5" type="radio" value="{{ $value }}" wire:model.live="targets.{{ $target }}.method">
                                        <span>
                                            <span class="block text-[13px] font-medium">{{ $label }}</span>
                                            <span class="block text-[12px] text-muted">{{ $hint }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2" x-show="method !== 'code'" x-cloak>
                            <label class="field">
                                <span>Username</span>
                                <input class="input" wire:model="targets.{{ $target }}.username" autocomplete="off" spellcheck="false">
                                @error('targets.'.$target.'.username') <small class="error">{{ $message }}</small> @enderror
                            </label>
                            <label class="field">
                                <span class="flex items-center gap-2">
                                    Password
                                    @if ($hasPassword[$target])
                                        <span class="pill" style="height: 18px; font-size: 10.5px">Saved</span>
                                    @endif
                                </span>
                                <input class="input" type="password" wire:model="targets.{{ $target }}.password" autocomplete="new-password" placeholder="{{ $hasPassword[$target] ? 'Leave empty to keep the saved one' : 'At least 8 characters' }}">
                                @error('targets.'.$target.'.password') <small class="error">{{ $message }}</small> @enderror
                            </label>
                        </div>

                        <x-callout type="info" title="How to sign in">
                            <span x-show="method === 'code'">Type your current 6-digit authenticator code in the <b>Username</b> box (or the Password box) and press Sign in.</span>
                            <span x-show="method === 'password'" x-cloak>Type the username and password you set here.</span>
                            <span x-show="method === 'password_code'" x-cloak>Type the username. In the password box, type the password and then your 6-digit code with no space, like <code>mypassword123456</code>.</span>
                            The browser then stays signed in for {{ $rememberHours }} {{ \Illuminate\Support\Str::plural('hour', $rememberHours) }}. Five wrong tries block that IP for 15 minutes.
                        </x-callout>
                    </div>
                </div>
            </x-layer-card>
        @endforeach

        <x-layer-card title="Allowed IPs" helper="These IPs never see the sign-in box, for the panel and for phpMyAdmin. One IP or range per line.">
            <div class="flex max-w-xl flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line bg-recessed px-3 py-2 text-[13px]">
                    <span>Your IP right now: <code class="font-mono">{{ $myIp }}</code></span>
                    <button class="btn btn-sm" type="button" wire:click="addMyIp"><x-menu-icon name="plus" /> Add my IP</button>
                </div>
                <label class="field">
                    <span>IPs and ranges</span>
                    <textarea class="input min-h-32 font-mono text-[13px]" wire:model="allowlist" spellcheck="false" placeholder="203.0.113.7&#10;198.51.100.0/24&#10;2001:db8::/32"></textarea>
                    <small class="text-muted">IPv4 or IPv6. A range uses CIDR, like 198.51.100.0/24.</small>
                    @error('allowlist') <small class="error">{{ $message }}</small> @enderror
                </label>
            </div>
        </x-layer-card>

        <x-layer-card title="Stay signed in" helper="How long a browser can open the panel or phpMyAdmin again without the sign-in box.">
            <div class="segmented self-start" role="group" aria-label="Stay signed in for">
                @foreach ($rememberChoices as $hours)
                    <button type="button" aria-pressed="{{ $rememberHours === $hours ? 'true' : 'false' }}" wire:click="$set('rememberHours', {{ $hours }})">{{ $hours }} {{ \Illuminate\Support\Str::plural('hour', $hours) }}</button>
                @endforeach
            </div>
        </x-layer-card>

        <x-callout type="warning" title="Locked out?">
            On the server, run <code>sudo -u www-data php /opt/laravelify/artisan laravelify:access-off</code> to turn the sign-in box off again.
        </x-callout>

        <div>
            <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="check"><x-menu-icon name="lock" /> Save</button>
        </div>
    </form>

    {{-- Saving asks for the current authenticator code --}}
    <x-modal-frame class="modal-root-top" open="otp.open" close="otp.open = false">
        <form class="flex flex-col gap-4" @submit.prevent="submit()">
            <div class="modal-head">
                <h2 class="flex items-center gap-2"><x-menu-icon name="lock" class="size-4 text-muted" /> Confirm the access settings</h2>
                <button class="icon-btn" type="button" @click="otp.open = false" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>

            <p class="text-[13px] text-muted">To confirm it is you, enter the current 6-digit code from your authenticator app.</p>

            <label class="field">
                <span>Authenticator code</span>
                <input class="input otp-input" x-ref="code" x-model="otp.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" @input="otp.code = otp.code.replace(/\D/g, '').slice(0, 6); otp.code.length === 6 && submit()">
                @error('code') <small class="error">{{ $message }}</small> @enderror
            </label>

            <div class="modal-foot">
                <button class="btn" type="button" @click="otp.open = false">Cancel</button>
                <button class="btn btn-primary" type="submit" :disabled="otp.code.length < 6 || otp.busy">
                    <span class="task-spinner" x-show="otp.busy" x-cloak></span>
                    Save
                </button>
            </div>
        </form>
    </x-modal-frame>
</div>
