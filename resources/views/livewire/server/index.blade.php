<div class="flex flex-col gap-6">
    <x-page-header title="Server" summary="MySQL 8.4 LTS, Redis, phpMyAdmin, and PHP-FPM 8.0 through 8.5. Edit a service to change its config and apply it.">
        <x-slot:actions>
            <a class="btn" href="{{ route('server.nginx') }}" wire:navigate>Nginx</a>
            <a class="btn" href="{{ route('server.ports') }}" wire:navigate>Reserved ports</a>
            <a class="btn" href="{{ route('server.processes') }}" wire:navigate>PM2 and Supervisor</a>
        </x-slot:actions>
    </x-page-header>

    <section class="layer-card-body is-flush">
        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Domain</th>
                        <th>Login</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($services as $service)
                        @php($status = $statuses[$service->id] ?? 'unknown')
                        <tr wire:key="service-{{ $service->id }}">
                            <td class="font-medium">{{ $service->name }}</td>
                            <td class="text-muted">{{ $service->domain ?: '-' }}</td>
                            <td class="text-muted">
                                @if ($service->kind === 'mysql')
                                    root
                                @elseif ($service->kind === 'redis')
                                    {{ ($service->settings['password'] ?? '') !== '' ? 'password set' : 'no password' }}
                                @elseif ($service->kind === 'phpmyadmin')
                                    MySQL root
                                @else
                                    -
                                @endif
                            </td>
                            <td>
                                <span class="status-badge" style="--status-color: {{ match (true) { str_contains($status, 'active') || str_contains($status, 'running') => 'var(--color-success)', str_contains($status, 'inactive') || str_contains($status, 'failed') || str_contains($status, 'stopped') => 'var(--color-danger)', default => 'var(--color-muted)' } }}">{{ $status }}</span>
                            </td>
                            <td class="text-right"><a class="btn" href="{{ route('server.edit', $service) }}" wire:navigate>Edit</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <p class="text-[12px] text-muted">Install them with <code>sudo bash scripts/install-server.sh</code>.</p>
</div>
