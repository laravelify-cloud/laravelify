{{-- Settings → MCP: tokens for AI clients and how to connect them. --}}
@php
    $time = app(\App\Services\DisplayTime::class);
    $token = $newToken !== '' ? $newToken : 'YOUR_TOKEN';
    $claudeCode = 'claude mcp add --transport http laravelify '.$endpoint.' --header "Authorization: Bearer '.$token.'"';
    $jsonConfig = json_encode(['mcpServers' => ['laravelify' => ['url' => $endpoint, 'headers' => ['Authorization' => 'Bearer '.$token]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $desktopConfig = json_encode(['mcpServers' => ['laravelify' => [
        'command' => 'npx',
        'args' => ['-y', 'mcp-remote', $endpoint, '--header', 'Authorization:${AUTH}'],
        'env' => ['AUTH' => 'Bearer '.$token],
    ]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
@endphp

<div
    class="flex flex-col gap-6"
    x-data="{
        otp: { open: false, code: '', busy: false },
        client: 'claude-code',
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
            $wire.create(this.otp.code).then((done) => {
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
    <x-page-header title="MCP" summary="Let an AI assistant (Claude Code, Claude Desktop, Cursor, …) deploy and manage your websites here: give it a repository and a domain, and it sets everything up." />

    @if ($newToken !== '')
        <x-callout type="success" title="Your new token: copy it now">
            <span class="block">It is shown only once. Anyone with it can manage this panel's websites, so keep it like a password.</span>
            <span class="mt-2 flex items-center gap-2">
                <code class="input flex-1 truncate font-mono leading-9">{{ $newToken }}</code>
                <x-copy-button :text="'\''.$newToken.'\''" />
                <button class="btn btn-sm" type="button" wire:click="dismissToken">Done</button>
            </span>
        </x-callout>
    @endif

    <x-layer-card title="1. Create a token" helper="One token per computer or app, so you can revoke one without touching the others. Creating it asks for your authenticator code.">
        <form class="flex max-w-xl flex-wrap items-end gap-2" @submit.prevent="$wire.check().then((ok) => ok && ask())">
            <label class="field min-w-56 flex-1">
                <span>Name</span>
                <input class="input" wire:model="tokenName" placeholder="Claude Code on my laptop" maxlength="80">
                @error('tokenName') <small class="error">{{ $message }}</small> @enderror
            </label>
            <button class="btn btn-primary" type="submit"><x-menu-icon name="plus" /> Create token</button>
        </form>

        @if ($tokens->isNotEmpty())
            <table class="table mt-4">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Token</th>
                        <th>Last used</th>
                        <th class="w-24"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tokens as $item)
                        <tr wire:key="mcp-token-{{ $item->id }}">
                            <td class="font-medium">{{ $item->name }}</td>
                            <td class="font-mono text-[12.5px] text-muted">{{ $item->prefix }}…</td>
                            <td class="text-muted">{{ $item->last_used_at ? $time->ago($item->last_used_at).' · '.$item->last_used_ip : 'Never' }}</td>
                            <td class="text-right">
                                <button class="btn btn-sm btn-danger" type="button" wire:click="revoke({{ $item->id }})" wire:confirm="Revoke {{ $item->name }}? Clients using it stop working.">Revoke</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-layer-card>

    <x-layer-card title="2. Connect your AI app" :helper="'The server address is '.$endpoint">
        <div class="flex flex-col gap-3">
            <div class="segmented self-start" role="group" aria-label="AI app">
                <button type="button" :aria-pressed="client === 'claude-code'" @click="client = 'claude-code'">Claude Code</button>
                <button type="button" :aria-pressed="client === 'desktop'" @click="client = 'desktop'">Claude Desktop</button>
                <button type="button" :aria-pressed="client === 'json'" @click="client = 'json'">Cursor and others</button>
            </div>

            <div x-show="client === 'claude-code'">
                <p class="mb-2 text-[13px] text-muted">Run this in a terminal{{ $newToken === '' ? ', with your token in place of YOUR_TOKEN' : '' }}:</p>
                <div class="flex items-start gap-2">
                    <pre class="sql-block flex-1 whitespace-pre-wrap break-all">{{ $claudeCode }}</pre>
                    <x-copy-button :text="\Illuminate\Support\Js::from($claudeCode)" />
                </div>
            </div>

            <div x-show="client === 'desktop'" x-cloak>
                <p class="mb-2 text-[13px] text-muted">Settings → Developer → Edit config, and add this to <code>claude_desktop_config.json</code> (needs Node.js). Restart Claude Desktop.</p>
                <div class="flex items-start gap-2">
                    <pre class="sql-block flex-1 whitespace-pre-wrap break-all">{{ $desktopConfig }}</pre>
                    <x-copy-button :text="\Illuminate\Support\Js::from($desktopConfig)" />
                </div>
            </div>

            <div x-show="client === 'json'" x-cloak>
                <p class="mb-2 text-[13px] text-muted">Cursor: <code>.cursor/mcp.json</code>. Other apps that take a remote MCP server with headers use the same shape.</p>
                <div class="flex items-start gap-2">
                    <pre class="sql-block flex-1 whitespace-pre-wrap break-all">{{ $jsonConfig }}</pre>
                    <x-copy-button :text="\Illuminate\Support\Js::from($jsonConfig)" />
                </div>
            </div>
        </div>
    </x-layer-card>

    <x-layer-card title="3. Ask it to deploy" helper="The assistant gets these tools: list and create groups and websites, deploy, follow the deploy log, set .env variables, and turn sites on or off.">
        <ul class="flex flex-col gap-2 text-[13.5px]">
            <li class="rounded-lg border border-line bg-recessed px-3 py-2">"Deploy <b>acme/shop</b> on <b>shop.example.com</b> with a MySQL database."</li>
            <li class="rounded-lg border border-line bg-recessed px-3 py-2">"Deploy this project folder to my Laravelify server on <b>app.example.com</b>." <span class="text-muted">It pushes the folder to a new private GitHub repository first, then deploys it.</span></li>
            <li class="rounded-lg border border-line bg-recessed px-3 py-2">"Why did the last deploy of <b>shop</b> fail? Fix it and deploy again."</li>
            <li class="rounded-lg border border-line bg-recessed px-3 py-2">"Set <b>MAIL_HOST</b> and <b>MAIL_PORT</b> on <b>shop</b>."</li>
        </ul>
        <p class="mt-3 text-[12.5px] text-muted">For private repositories, connect a GitHub App under Sources and install it on the repositories. Those sites then deploy again on every push. <a class="text-accent hover:underline" href="https://docs.laravelify.cloud/tools/mcp" target="_blank" rel="noreferrer">Docs</a></p>
    </x-layer-card>

    <x-modal-frame class="modal-root-top" open="otp.open" close="otp.open = false">
        <form class="flex flex-col gap-4" @submit.prevent="submit()">
            <div class="modal-head">
                <h2 class="flex items-center gap-2"><x-menu-icon name="lock" class="size-4 text-muted" /> Confirm the new token</h2>
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
                    Create token
                </button>
            </div>
        </form>
    </x-modal-frame>
</div>
