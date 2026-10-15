<div class="flex flex-col gap-6">
    <x-page-header title="Nginx" summary="Every site nginx serves, the ports and domains it holds, its config files, and its error log.">
        <x-slot:actions>
            <a class="btn" href="{{ route('server.index') }}" wire:navigate>Back to server</a>
            <button class="btn btn-primary" type="button" wire:click="runTest" wire:loading.attr="disabled" wire:target="runTest">
                <span wire:loading.remove wire:target="runTest">Test nginx</span>
                <span wire:loading wire:target="runTest">Testing…</span>
            </button>
        </x-slot:actions>
    </x-page-header>

    @if ($test)
        <div class="{{ $test['ok'] ? 'flex items-start gap-3 rounded-lg border border-line bg-recessed px-4 py-3' : 'callout' }}">
            <x-menu-icon name="{{ $test['ok'] ? 'check' : 'danger' }}" class="size-4 shrink-0 {{ $test['ok'] ? '' : 'text-warning' }}" style="{{ $test['ok'] ? 'color: var(--color-success)' : '' }}" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ $test['ok'] ? 'nginx -t passed' : 'nginx -t failed' }} <span class="font-normal text-muted">· {{ $test['at'] }}</span></p>
                <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap text-[12.5px]">{{ $test['output'] }}</pre>
            </div>
        </div>
    @endif

    @if ($conflicts !== [])
        <div class="callout">
            <x-menu-icon name="danger" class="size-4 shrink-0 text-warning" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Two server blocks answer for the same domain and port. nginx uses the first one it loads.</p>
                <ul class="mt-1 list-disc pl-5 text-[13px]">
                    @foreach ($conflicts as $conflict)
                        <li><code>{{ $conflict['name'] }}</code> on port {{ $conflict['port'] }}: {{ implode(', ', $conflict['files']) }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <x-layer-card title="Sites and domains" helper="Every server block in sites-enabled and conf.d." flush>
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Domains</th><th>Ports</th><th>Sends to</th><th>Owner</th><th>File</th></tr>
                </thead>
                <tbody>
                    @forelse ($servers as $server)
                        <tr wire:key="server-{{ $loop->index }}">
                            <td class="font-mono text-[12.5px]">{{ implode(' ', $server['names']) ?: '-' }}</td>
                            <td>{{ implode(', ', $server['ports']) ?: '-' }}</td>
                            <td class="text-[12.5px] text-muted">{{ implode(' · ', $server['targets']) ?: '-' }}</td>
                            <td>
                                @if ($server['website'])
                                    <a class="underline" href="{{ route('websites.show', ['website' => $server['website'], 'tab' => 'nginx']) }}" wire:navigate>{{ $server['owner'] }}</a>
                                @else
                                    <span class="{{ $server['owner'] === 'Not managed by Laravelify' ? 'text-warning' : 'text-muted' }}">{{ $server['owner'] }}</span>
                                @endif
                            </td>
                            <td class="font-mono text-[12px] text-muted">{{ $server['file'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No nginx files found here.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-layer-card>

    <x-layer-card title="Ports nginx listens on" helper="How many server blocks use each port.">
        <div class="flex flex-wrap gap-2">
            @forelse ($ports as $port => $count)
                <span class="pill">{{ $port }} <span class="text-muted">· {{ $count }}</span></span>
            @empty
                <span class="text-muted">None.</span>
            @endforelse
        </div>
        <p class="mt-3 text-[13px]">Every port on the machine, and what holds it: <a class="underline" href="{{ route('server.ports') }}" wire:navigate>Reserved ports</a>.</p>
    </x-layer-card>

    <x-layer-card title="Error log" helper="The last lines of /var/log/nginx/error.log.">
        <x-slot:actions>
            <select class="input w-auto" wire:model="lines" data-native aria-label="Lines">
                @foreach ([100, 200, 500, 1000] as $count)
                    <option value="{{ $count }}">{{ $count }} lines</option>
                @endforeach
            </select>
            <button class="btn" type="button" wire:click="loadLog" wire:loading.attr="disabled" wire:target="loadLog">{{ $log === null ? 'Show log' : 'Refresh' }}</button>
        </x-slot:actions>
        @if ($log === null)
            <p class="text-[13px] text-muted">Press Show log. A site's own access and error logs are on its Logs tab.</p>
        @else
            <pre class="max-h-[28rem] overflow-auto whitespace-pre-wrap font-mono text-[12px]">{{ $log !== '' ? $log : 'The log is empty.' }}</pre>
        @endif
    </x-layer-card>

    <x-layer-card title="Config files" helper="Read-only. Change a site's file on its Nginx tab." flush>
        <div class="flex flex-col divide-y divide-line">
            @foreach ($files as $file)
                <details class="px-4 py-2.5" wire:key="file-{{ $file['name'] }}">
                    <summary class="cursor-pointer font-mono text-[13px]">{{ $file['name'] }} <span class="text-muted">· {{ count($file['servers']) }} server{{ count($file['servers']) === 1 ? '' : 's' }}</span></summary>
                    <pre class="mt-2 max-h-96 overflow-auto whitespace-pre font-mono text-[12px]">{{ $file['content'] }}</pre>
                </details>
            @endforeach
        </div>
    </x-layer-card>
</div>
