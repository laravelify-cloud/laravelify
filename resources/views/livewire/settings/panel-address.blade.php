<div class="flex flex-col gap-6">
    <x-page-header title="Panel address" summary="Where you open this panel. It starts on a port of your server; give it a domain so it uses HTTPS." />

    <x-layer-card title="Current address">
        <p class="text-[13.5px]"><code>{{ $currentUrl }}</code></p>

        @if ($onPort)
            <x-callout class="mt-3" type="warning" title="This address is not encrypted">
                Passwords and codes travel as plain HTTP on this address. Give the panel a domain before you use it for real work.
            </x-callout>
        @endif
    </x-layer-card>

    <x-layer-card title="Move the panel to a domain" helper="The domain is tested first. If it does not work, nothing changes and this address keeps working.">
        <form class="flex max-w-xl flex-col gap-4" wire:submit="move">
            <label class="flex flex-col gap-1.5">
                <span class="text-[13px] font-medium">Domain</span>
                <input class="input" type="text" wire:model="domain" placeholder="panel.example.com" autocomplete="off" spellcheck="false">
                @error('domain') <span class="text-[12.5px] text-danger">{{ $message }}</span> @enderror
            </label>

            <fieldset class="flex flex-col gap-2">
                <legend class="mb-1 text-[13px] font-medium">How the domain reaches this server</legend>
                <label class="flex items-start gap-2 text-[13.5px]">
                    <input type="radio" class="mt-1" wire:model.live="method" value="cloudflare">
                    <span><b>Cloudflare API</b>: Laravelify makes the record for you.@unless ($hasToken) <a class="text-accent hover:underline" href="{{ route('settings.cloudflare') }}" wire:navigate>Save an API token first.</a>@endunless</span>
                </label>
                <label class="flex items-start gap-2 text-[13.5px]">
                    <input type="radio" class="mt-1" wire:model.live="method" value="dns">
                    <span><b>DNS record</b>: you make the record yourself.</span>
                </label>
            </fieldset>

            @if ($method === 'cloudflare' && $tunnels->isNotEmpty())
                <label class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-medium">Cloudflare tunnel</span>
                    <select class="input" wire:model="tunnel">
                        <option value="">No tunnel: a proxied A record to this server</option>
                        @foreach ($tunnels as $item)
                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($method === 'dns')
                <x-callout type="info" title="Make this record in Cloudflare first">
                    Type <b>A</b>, name <b>your domain</b>, content <b>{{ $serverIp ?? 'this server\'s public IP' }}</b>, with the orange cloud on (<b>Proxied</b>). Set SSL/TLS to <b>Flexible</b>. Cloudflare is what gives the panel HTTPS, so a record that is not proxied will not pass the test.
                </x-callout>
            @endif

            @if ($problem)
                <x-callout type="danger" title="The panel did not move">{{ $problem }}</x-callout>
            @endif

            <div>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="move">
                    <span wire:loading.remove wire:target="move">Test and move</span>
                    <span wire:loading wire:target="move">Testing the domain, up to 40 seconds…</span>
                </button>
            </div>
            <p class="text-[12.5px] text-muted">After the move you sign in again on the new address, and the old port redirects to it.</p>
        </form>
    </x-layer-card>
</div>
