<div class="flex flex-col gap-6">
    <x-page-header title="General" summary="How the panel shows dates and times. Stored data keeps UTC; only what you see changes." />

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
                            @foreach ($timezones as $region => $zones)
                                <optgroup label="{{ $region }}">
                                    @foreach ($zones as $identifier => $label)
                                        <option value="{{ $identifier }}">{{ $label }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <button class="btn" type="button" @click="$wire.set('timezone', Intl.DateTimeFormat().resolvedOptions().timeZone)" title="Use this browser's timezone">
                            <x-menu-icon name="globe" /> Use my timezone
                        </button>
                    </div>
                    <span class="text-[12px] text-muted">All {{ collect($timezones)->flatten()->count() }} IANA timezones, with today's UTC offset. Type in the list to jump, like "Baku" or "Istanbul".</span>
                    @error('timezone') <span class="error text-[12px]">{{ $message }}</span> @enderror
                </label>

                <div class="grid gap-4 md:grid-cols-2">
                    <label class="field">
                        <span class="label">Date format</span>
                        <select class="select" wire:model.live="date_format">
                            @foreach (\App\Services\DisplayTime::DATE_FORMATS as $format => $example)
                                <option value="{{ $format }}">{{ $example }}</option>
                            @endforeach
                        </select>
                        @error('date_format') <span class="error text-[12px]">{{ $message }}</span> @enderror
                    </label>
                    <label class="field">
                        <span class="label">Time format</span>
                        <select class="select" wire:model.live="time_format">
                            @foreach (\App\Services\DisplayTime::TIME_FORMATS as $format => $label)
                                <option value="{{ $format }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('time_format') <span class="error text-[12px]">{{ $message }}</span> @enderror
                    </label>
                </div>

                <label class="flex items-start justify-between gap-4 rounded-lg border border-line bg-recessed px-3 py-2.5">
                    <span>
                        <span class="text-[13.5px] font-medium">Show relative times</span>
                        <span class="mt-0.5 block text-[12px] text-muted">"5 minutes ago" for recent events, with the full date when you hover. Off shows the full date and time everywhere.</span>
                    </span>
                    <input class="mt-1" type="checkbox" wire:model.live="relative">
                </label>
            </div>
        </x-layer-card>

        <x-layer-card title="Preview" helper="Right now in {{ $timezone }} (UTC{{ $preview['offset'] }}).">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><p class="mon-label">Date and time</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['datetime'] }}</p></div>
                <div><p class="mon-label">Date</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['date'] }}</p></div>
                <div><p class="mon-label">Time</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['time'] }}</p></div>
                <div><p class="mon-label">A deploy 5 minutes ago</p><p class="mt-1.5 font-mono text-[15px] font-semibold">{{ $preview['earlier'] }}</p></div>
            </div>
        </x-layer-card>
    </form>
</div>
