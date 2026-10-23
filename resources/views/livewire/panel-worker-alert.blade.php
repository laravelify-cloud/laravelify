<div wire:poll.60s class="contents">
    @unless ($running)
        <x-callout type="danger" title="The panel's background worker is not running">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span class="min-w-0 flex-1">
                    Deploys, deletes, and other background tasks wait until it runs{{ $since ? ' (down since '.\Illuminate\Support\Carbon::createFromTimestamp($since)->diffForHumans().')' : '' }}.
                    Laravelify starts it again by itself within a minute; if this stays, start it here or run <code>supervisorctl start laravelify-queue</code> on the server.
                </span>
                <button class="btn btn-primary" type="button" wire:click="start" wire:loading.attr="disabled" @disabled($starting)>
                    {{ $starting ? 'Starting…' : 'Start it' }}
                </button>
            </div>
        </x-callout>
    @endunless
</div>
