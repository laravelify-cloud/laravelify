<div class="flex flex-col gap-6">
    <x-page-header title="General" summary="How the panel shows dates and times, and the address links to websites on a port use." />

    <form class="flex max-w-3xl flex-col gap-6" wire:submit="save" x-data>
        <x-layer-card title="Date and time" helper="Used on every page: deployments, logs, monitoring, files, charts, and live feeds.">
            <x-slot:actions>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="save">Save</button>
            </x-slot:actions>

            <div class="flex flex-col gap-5">
                <label class="field">
                    <span class="label">Timezone</span>
                    <div class="flex flex-wrap gap-2">
                        <select class="select min-w-0 flex-1" wire:model.live="timezone">
                            <option value="">Automatic: each browser's own timezone{{ $browserZone ? ' (yours: '.str_replace('_', ' ', $browserZone).')' : '' }}</option>
                            @foreach ($timezones as $region => $zones)
                                <optgroup label="{{ $region }}">
                                    @foreach ($zones as $identifier => $label)
                                        <option value="{{ $identifier }}">{{ $label }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <button class="btn" type="button" @click="$wire.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone)" title="Fix the panel to this browser's timezone for everyone">
                            <x-menu-icon name="globe" /> Use my timezone for everyone
                        </button>
                    </div>
                    <span class="text-[12px] text-muted">Automatic shows every person the times of their own browser. Or pick one of the {{ collect($timezones)->flatten()->count() }} IANA timezones for everyone; type in the list to jump, like "Baku".</span>
                    @error('timezone') <span class="error text-[12px]">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-4 md:grid-cols-2">
                    <label class="field">
                        <span class="label">Date format</span>
                        <select class="select" wire:model.live="date_format">
                            <option value="auto">Automatic, from the browser's country</option>
                            @foreach (\App\Services\DisplayTime::DATE_FORMATS as $format => $example)
                                <option value="{{ $format }}">{{ $example }}</option>
                            @endforeach
                        </select>
                        @error('date_format') <span class="error text-[12px]">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">
                        <span class="label">Time format</span>
                        <select class="select" wire:model.live="time_format">
                            <option value="auto">Automatic, from the browser's country</option>
                            @foreach (\App\Services\DisplayTime::TIME_FORMATS as $format => $label)
                                <option value="{{ $format }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('time_format') <span class="error text-[12px]">{{ $message }}</span> @enderror
                    </label>
                </div>
                <p class="-mt-2 text-[12px] text-muted">Automatic follows the browser's language and country: {{ $browserLocale }} shows <span class="font-mono text-ink">{{ $autoExample }}</span>.</p>

                <label class="flex items-start justify-between gap-4 rounded-lg border border-line bg-recessed px-3 py-2.5">
                    <span>
                        <span class="text-[13.5px] font-medium">Show relative times</span>
                        <span class="mt-0.5 block text-[12px] text-muted">"5 minutes ago" for recent events, with the full date when you hover. Off shows the full date and time everywhere.</span>
                    </span>
                    <input class="mt-1" type="checkbox" wire:model.live="relative">
                </label>
            </div>
        </x-layer-card>

        <x-layer-card title="Preview" helper="Right now in {{ str_replace('_', ' ', $shownZone) }} (UTC{{ $preview['offset'] }}){{ $timezone === '' ? ', the timezone of your browser' : '' }}.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><p class="mon-label">Date and time</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['datetime'] }}</p></div>
                <div><p class="mon-label">Date</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['date'] }}</p></div>
                <div><p class="mon-label">Time</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['time'] }}</p></div>
                <div><p class="mon-label">A deploy 5 minutes ago</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['earlier'] }}</p></div>
            </div>
        </x-layer-card>
    </form>

    <form class="flex max-w-3xl flex-col gap-6" wire:submit="saveServerAddress">
        <x-layer-card title="Server address" helper="Websites without a domain open on http://address:port. Set it when the found address is wrong, like a server behind NAT or on a local network (WSL).">
            <x-slot:actions>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="saveServerAddress">Save</button>
            </x-slot:actions>

            <div class="flex flex-col gap-3">
                <label class="field">
                    <span class="label">IP address or host name</span>
                    <input class="input font-mono" type="text" wire:model="server_address" placeholder="{{ $detectedAddress ?? '192.168.1.20' }}" autocomplete="off" spellcheck="false">
                    <span class="text-[12px] text-muted">
                        Leave empty to find it automatically{{ $detectedAddress ? ': now '.$detectedAddress : '' }}.
                    </span>
                    @error('server_address') <span class="error text-[12px]">{{ $message }}</span> @enderror
                </label>

                @if ($localIps !== [] || $detectedAddress)
                    <div class="flex flex-wrap items-center gap-2 text-[12px] text-muted">
                        <span>Use:</span>
                        @foreach (array_values(array_unique(array_filter([$detectedAddress, ...$localIps]))) as $ip)
                            <button class="btn btn-sm font-mono" type="button" wire:click="$set('server_address', '{{ $ip }}')">{{ $ip }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-layer-card>
    </form>
</div>
