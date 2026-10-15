<div class="flex flex-col gap-6">
    <x-page-header title="Cloudflare" summary="Let Laravelify add each website's domains to its Cloudflare tunnel and DNS, so you do not do it by hand." />

    <x-layer-card title="API token" helper="Stored encrypted. Without a token everything still works; you add the public hostname and the DNS record in Cloudflare yourself.">
        <form class="flex max-w-xl flex-col gap-4" wire:submit="save">
            <x-secret-input label="Cloudflare API token" model="token" secret="cloudflare_api_token" :saved="$hasToken" :placeholder="$hasToken ? null : 'Paste the token'" />

            <div class="flex flex-wrap gap-2">
                <button class="btn btn-primary" type="submit">{{ $hasToken ? 'Replace token' : 'Save token' }}</button>
                @if ($hasToken)
                    <button class="btn" type="button" wire:click="test" wire:loading.attr="disabled" wire:target="test"><x-menu-icon name="refresh" /> Test token</button>
                    <x-confirm-delete
                        title="Remove the Cloudflare token?"
                        name="cloudflare-token"
                        action="clear"
                        button="Remove token"
                        :actions="['Laravelify stops adding domains to Cloudflare. Tunnel routes and DNS records already made stay.']"
                    />
                @endif
            </div>

            @if ($result)
                <x-callout :type="$result['ok'] ? 'success' : 'danger'" :title="$result['ok'] ? 'Token works' : 'Token problem'">
                    {{ $result['message'] }}
                    @if ($result['zones'] !== [])
                        <span class="mt-1 block text-[12.5px]">Domains it can change: {{ implode(', ', array_slice($result['zones'], 0, 12)) }}{{ count($result['zones']) > 12 ? ', …' : '' }}</span>
                    @endif
                </x-callout>
            @endif
        </form>
    </x-layer-card>

    <x-layer-card title="Make the token" helper="About two minutes, once.">
        <ol class="flex list-decimal flex-col gap-1.5 pl-4 text-[13.5px]">
            <li>Open <a class="text-accent hover:underline" href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noreferrer">Cloudflare → My Profile → API Tokens</a> and choose <b>Create Token → Create Custom Token</b>.</li>
            <li>Add these permissions:
                <ul class="mt-1 list-disc pl-5 text-muted">
                    <li><b class="text-ink">Account</b> → <b class="text-ink">Cloudflare Tunnel</b> → <b class="text-ink">Edit</b></li>
                    <li><b class="text-ink">Zone</b> → <b class="text-ink">DNS</b> → <b class="text-ink">Edit</b></li>
                    <li><b class="text-ink">Zone</b> → <b class="text-ink">Zone</b> → <b class="text-ink">Read</b></li>
                </ul>
            </li>
            <li>Under <b>Zone Resources</b> choose <b>All zones</b> (or the domains you use), and create the token.</li>
            <li>Paste it above and press <b>Save token</b>. The test shows the domains it can change.</li>
        </ol>
        <p class="mt-3 text-[12.5px] text-muted">
            Then, whenever a website on a tunnel gets a domain (or a <code>www</code> or second domain), Laravelify adds the public hostname to the tunnel and a proxied CNAME to <code>&lt;tunnel&gt;.cfargotunnel.com</code>.
            It never overwrites a DNS record that already exists for something else, and it leaves the tunnel's other routes alone.
        </p>
    </x-layer-card>
</div>
