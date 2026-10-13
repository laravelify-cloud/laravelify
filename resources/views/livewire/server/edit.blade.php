<form class="card flex flex-col gap-4 p-4" wire:submit="save">
    <p class="text-muted">
        @if ($service->kind === 'phpmyadmin')
            Set the domain below. Saving rewrites <code>server_name</code> in the nginx file and applies it. TLS stays at Cloudflare, and this vhost listens on port 80.
            phpMyAdmin has no password of its own. On its login form use MySQL user <code>root</code> and the root password from the MySQL page. The Redis password is on the Redis page. Both fields have an eye icon.
        @elseif ($service->kind === 'php-fpm')
            Apply overwrites <code>/etc/php/{{ $service->version }}/fpm/pool.d/www.conf</code> and restarts <code>php{{ $service->version }}-fpm</code>.
        @elseif ($service->kind === 'mysql')
            Domain is the host websites should use. Bind and port are written into the MySQL config, then MySQL restarts. phpMyAdmin logs in with this root user and root password. The eye on the password field shows it. Saving a new password changes MySQL and the panel.
        @else
            Domain is the host websites should use. Bind, port, and password are written into the Redis config, then Redis restarts.
        @endif
    </p>

    @if (in_array($service->kind, ['mysql', 'redis', 'phpmyadmin']))
        <div class="grid gap-4 md:grid-cols-2">
            <label class="field">
                <span>Domain</span>
                <input class="input" wire:model="domain" placeholder="{{ $service->kind === 'phpmyadmin' ? 'db.example.com' : '127.0.0.1' }}">
                @if ($service->kind === 'phpmyadmin')
                    <small class="text-muted">Public hostname for phpMyAdmin. In Cloudflare, send that hostname to <code>http://localhost:8080</code>.</small>
                @else
                    <small class="text-muted">Use <code>127.0.0.1</code> for sites running on this machine.</small>
                @endif
                @error('domain') <small class="error">{{ $message }}</small> @enderror
            </label>

            @if (in_array($service->kind, ['mysql', 'redis']))
                <label class="field">
                    <span>Bind address</span>
                    <input class="input" wire:model="bind">
                    @error('bind') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Port</span>
                    <input class="input" wire:model="port" inputmode="numeric">
                    @error('port') <small class="error">{{ $message }}</small> @enderror
                </label>
            @endif

            @if ($service->kind === 'mysql')
                <label class="field">
                    <span>Root user</span>
                    <input class="input" value="root" readonly>
                </label>
                <x-secret-input label="Root password" model="password" />
                <small class="text-muted">phpMyAdmin username is <code>root</code>. This is the password. Click the eye to reveal it.</small>
            @endif

            @if ($service->kind === 'redis')
                <x-secret-input label="Redis password" model="password" />
                <small class="text-muted">Click the eye to reveal it. Leave it empty to allow local connections without a password. Websites use host <code>127.0.0.1</code> and this password.</small>
            @endif

            @if ($service->kind === 'phpmyadmin')
                <div class="field">
                    <span>PHP version</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($phpVersions as $version)
                            <button class="choice {{ $php_version === $version ? 'active' : '' }}" type="button" wire:click="$set('php_version', '{{ $version }}')">{{ $version }}</button>
                        @endforeach
                    </div>
                    @error('php_version') <small class="error">{{ $message }}</small> @enderror
                </div>
            @endif
        </div>
    @endif

    <label class="field">
        <span>Config</span>
        <textarea class="textarea" wire:model="config"></textarea>
        @error('config') <small class="error">{{ $message }}</small> @enderror
    </label>
    <div class="flex gap-2">
        <button class="btn btn-primary" type="submit">Save and apply</button>
        <a class="btn" href="{{ route('server.index') }}" wire:navigate>Back</a>
    </div>
</form>
