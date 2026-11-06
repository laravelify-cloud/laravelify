<?php

namespace App\Livewire\Server;

use App\Livewire\PanelComponent;
use App\Models\ServerService;
use App\Services\HostWriter;
use App\Services\PhpMyAdminAccess;
use App\Services\ServerCatalog;
use App\Services\ServicePassword;
use Illuminate\Validation\Rule;
use Throwable;

class Edit extends PanelComponent
{
    public ServerService $service;

    public string $config = '';

    public string $domain = '';

    public string $bind = '127.0.0.1';

    public string $port = '';

    public string $password = '';

    /** The password field was shown or typed in; until then an empty field keeps the saved password. */
    public bool $passwordTouched = false;

    public string $php_version = '8.4';

    /** phpMyAdmin at this path of the panel's address ('' when it has a domain of its own). */
    public string $path = '';

    public function mount(ServerService $service): void
    {
        $this->service = $service;
        $this->config = $service->config;

        $defaults = ServerCatalog::connectionDefaults($service->kind);
        $settings = $service->settings ?? $defaults['settings'] ?? [];

        $this->domain = (string) ($service->domain ?? $defaults['domain'] ?? '');
        $this->bind = (string) ($settings['bind'] ?? '127.0.0.1');
        $this->port = (string) ($settings['port'] ?? '');
        // Passwords stay on the server: the eye shows them after the authenticator code.
        $this->password = '';
        $this->php_version = (string) ($settings['php_version'] ?? '8.4');
        $this->path = $service->kind === 'phpmyadmin' ? (string) ($settings['path'] ?? '') : '';
    }

    /** phpMyAdmin is a path of the panel (https://panel.example.com/phpmyadmin), not a domain of its own. */
    public function usesPath(): bool
    {
        return $this->service->kind === 'phpmyadmin' && filled($this->service->settings['path'] ?? null);
    }

    public function updatedPassword(): void
    {
        $this->passwordTouched = true;
    }

    /** The saved MySQL root or Redis password. */
    private function savedPassword(): string
    {
        $password = (string) ($this->service->settings['password'] ?? '');

        if ($password === '' && $this->service->kind === 'mysql') {
            return (string) config('database.connections.mysql.password');
        }

        if ($password === '' && $this->service->kind === 'redis') {
            $configured = config('database.redis.default.password');

            return is_string($configured) && $configured !== 'null' ? $configured : '';
        }

        return $password;
    }

    protected function revealableSecret(string $key): ?string
    {
        return $key === 'service_password' && in_array($this->service->kind, ['mysql', 'redis'], true) ? $this->savedPassword() : null;
    }

    public function save(HostWriter $hostWriter, ServicePassword $passwords): void
    {
        if (in_array($this->service->kind, ['mysql', 'redis'], true) && ! $this->passwordTouched) {
            $this->password = $this->savedPassword();
        }

        $rules = [
            'config' => ['required', 'string'],
        ];

        if ($this->usesPath()) {
            $this->path = '/'.strtolower(trim($this->path, ' /'));
            $rules['path'] = ['required', 'string', 'max:40', 'regex:/^\/[a-z0-9][a-z0-9_-]{1,38}$/'];
        } elseif ($this->hasConnectionSettings()) {
            $rules['domain'] = ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/'];
        }

        if (in_array($this->service->kind, ['mysql', 'redis'], true)) {
            $rules['bind'] = ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/'];
            $rules['port'] = ['required', 'integer', 'between:1,65535'];
            $rules['password'] = ['nullable', 'string', 'max:200', 'regex:/^[^\s]*$/'];
        }

        if ($this->service->kind === 'phpmyadmin') {
            $rules['php_version'] = ['required', Rule::in(config('laravelify.php_versions'))];
        }

        $this->validate($rules, ['path.regex' => 'A path like /phpmyadmin: a slash, then letters, numbers, dashes and underscores.']);
        $this->syncConfig();

        if ($this->usesPath()) {
            // The panel's address, then the path: what the sidebar link and sign-in open.
            $this->domain = (string) preg_replace('#/.*$#', '', (string) $this->service->domain).$this->path;
        }

        $this->service->update([
            'config' => $this->config,
            'domain' => $this->hasConnectionSettings() ? $this->domain : $this->service->domain,
            'settings' => $this->settingsPayload(),
        ]);

        try {
            $hostWriter->writeService($this->service);
            if (in_array($this->service->kind, ['mysql', 'redis'], true)) {
                $passwords->apply($this->service->kind, $this->password);
            }
            $this->toast('Config saved and applied.');
            $this->password = '';
            $this->passwordTouched = false;
        } catch (Throwable $exception) {
            $this->addError('config', $exception->getMessage());
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function render()
    {
        return view('livewire.server.edit', [
            'phpVersions' => config('laravelify.php_versions'),
        ])
            ->layout('layouts.app')
            ->title($this->service->name);
    }

    private function hasConnectionSettings(): bool
    {
        return in_array($this->service->kind, ['mysql', 'redis', 'phpmyadmin'], true);
    }

    /**
     * @return array<string, string>|null
     */
    private function settingsPayload(): ?array
    {
        return match ($this->service->kind) {
            'mysql' => ['bind' => $this->bind, 'port' => (string) $this->port, 'username' => 'root', 'password' => $this->password],
            'redis' => ['bind' => $this->bind, 'port' => (string) $this->port, 'password' => $this->password],
            'phpmyadmin' => array_filter(['php_version' => $this->php_version, 'path' => $this->path]),
            default => $this->service->settings,
        };
    }

    private function syncConfig(): void
    {
        if ($this->usesPath()) {
            // A new path: the locations are written again for it (sign-in included).
            $old = (string) ($this->service->settings['path'] ?? '');

            if ($old !== $this->path || ! ServerCatalog::isPathConfig($this->config)) {
                $this->config = ServerCatalog::phpMyAdminPathNginx($this->path, ServerCatalog::panelSocket($this->php_version));
            } else {
                $this->config = (string) preg_replace(ServerCatalog::SOCKET_PATTERN, ServerCatalog::panelSocket($this->php_version), $this->config);
            }

            return;
        }

        if ($this->service->kind === 'phpmyadmin') {
            $this->config = (string) preg_replace(
                '/server_name\s+[^;]+;/',
                'server_name '.$this->domain.';',
                $this->config,
                1,
            );
            // Every fastcgi_pass: phpMyAdmin itself and the Laravelify sign-in script. Once the panel
            // has its own user, phpMyAdmin runs in the panel's pool (the panel's PHP version).
            $this->config = (string) preg_replace(
                ServerCatalog::SOCKET_PATTERN,
                ServerCatalog::panelSocket($this->php_version),
                $this->config,
            );

            // The access prompt runs the panel's code and keeps the panel's PHP.
            $access = app(PhpMyAdminAccess::class);

            if ($access->hasGate($this->config)) {
                $this->config = $access->withGate($this->config, true, PhpMyAdminAccess::panelPhpVersion(), base_path());
            }

            return;
        }

        if ($this->service->kind === 'mysql') {
            $this->config = $this->setAssignment($this->config, 'bind-address', $this->bind, ' = ');
            $this->config = $this->setAssignment($this->config, 'port', (string) $this->port, ' = ');

            return;
        }

        if ($this->service->kind === 'redis') {
            $this->config = $this->setAssignment($this->config, 'bind', $this->bind, ' ');
            $this->config = $this->setAssignment($this->config, 'port', (string) $this->port, ' ');
            $this->config = $this->password === ''
                ? (string) preg_replace('/^requirepass\s+.*\R?/m', '', $this->config)
                : $this->setAssignment($this->config, 'requirepass', $this->password, ' ');
        }
    }

    private function setAssignment(string $config, string $key, string $value, string $separator): string
    {
        $line = $key.$separator.$value;
        $pattern = '/^'.preg_quote($key, '/').'\s*'.preg_quote(trim($separator), '/').'\s*.*$/m';

        if (preg_match($pattern, $config)) {
            return (string) preg_replace($pattern, $line, $config, 1);
        }

        return rtrim($config)."\n".$line."\n";
    }
}
