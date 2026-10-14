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

    public string $php_version = '8.4';

    public function mount(ServerService $service): void
    {
        $this->service = $service;
        $this->config = $service->config;

        $defaults = ServerCatalog::connectionDefaults($service->kind);
        $settings = $service->settings ?? $defaults['settings'] ?? [];

        $this->domain = (string) ($service->domain ?? $defaults['domain'] ?? '');
        $this->bind = (string) ($settings['bind'] ?? '127.0.0.1');
        $this->port = (string) ($settings['port'] ?? '');
        $this->password = (string) ($settings['password'] ?? '');
        if ($this->password === '' && $service->kind === 'mysql') {
            $this->password = (string) config('database.connections.mysql.password');
        }
        if ($this->password === '' && $service->kind === 'redis') {
            $configured = config('database.redis.default.password');
            $this->password = is_string($configured) && $configured !== 'null' ? $configured : '';
        }
        $this->php_version = (string) ($settings['php_version'] ?? '8.4');
    }

    public function save(HostWriter $hostWriter, ServicePassword $passwords): void
    {
        $rules = [
            'config' => ['required', 'string'],
        ];

        if ($this->hasConnectionSettings()) {
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

        $this->validate($rules);
        $this->syncConfig();

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
            'phpmyadmin' => ['php_version' => $this->php_version],
            default => $this->service->settings,
        };
    }

    private function syncConfig(): void
    {
        if ($this->service->kind === 'phpmyadmin') {
            $this->config = (string) preg_replace(
                '/server_name\s+[^;]+;/',
                'server_name '.$this->domain.';',
                $this->config,
                1,
            );
            // Every fastcgi_pass: phpMyAdmin itself and the Laravelify sign-in script.
            $this->config = (string) preg_replace(
                '#unix:/run/php/php[0-9.]+-fpm\.sock#',
                'unix:/run/php/php'.$this->php_version.'-fpm.sock',
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
