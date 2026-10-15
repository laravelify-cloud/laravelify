<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\ServerService;
use App\Models\Website;
use Illuminate\Support\Facades\File;
use RuntimeException;

class HostWriter
{
    public function writeWebsite(Website $website): string
    {
        $this->renderSite($website);

        return $this->apply(['site', $website->slug, $website->type]);
    }

    /**
     * Only the nginx side of a site (security rules, blocked IPs, request log),
     * without restarting its workers.
     */
    public function writeNginx(Website $website): string
    {
        $this->renderSite($website);

        return $this->apply(['site-nginx', $website->slug]);
    }

    /**
     * Turn a site off: nginx answers with a "turned off" page and its processes stop.
     */
    public function disableWebsite(Website $website, string $nginxConfig): string
    {
        $this->renderSite($website);
        File::put(storage_path('app/rendered/sites/'.$website->slug.'/nginx.conf'), $nginxConfig);

        return $this->apply(['site-disable', $website->slug]);
    }

    private function renderSite(Website $website): void
    {
        $directory = storage_path('app/rendered/sites/'.$website->slug);
        $this->ensurePanelCanWrite($directory);
        $security = app(SiteSecurity::class);

        // Every site includes its security snippet: it also carries the Monitoring request log.
        if ($security->ensureInclude($website)) {
            $website->save();
        }

        File::put($directory.'/nginx.conf', (string) $website->nginx_config);
        File::put($directory.'/process.conf', (string) $website->process_config);
        File::put($directory.'/.env', (string) $website->env_content);
        File::put($directory.'/security-http.conf', $security->httpConfig($website));
        File::put($directory.'/security-server.conf', $security->serverConfig($website));
        File::put($directory.'/security.htpasswd', $security->htpasswd($website));
    }

    public function writeTunnel(CloudflareTunnel $tunnel): string
    {
        $directory = storage_path('app/rendered/tunnels/'.$tunnel->slug);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/config.yml', (string) $tunnel->ingress);
        File::put($directory.'/tunnel.service', $this->tunnelUnit($tunnel));

        return $this->apply(['cloudflared', $tunnel->slug]);
    }

    public function writeService(ServerService $service): string
    {
        $directory = storage_path('app/rendered/services');
        File::ensureDirectoryExists($directory);

        $filename = match ($service->kind) {
            'mysql' => 'mysql.cnf',
            'redis' => 'redis.conf',
            'phpmyadmin' => 'phpmyadmin.conf',
            'php-fpm' => 'php-fpm-'.$service->version.'.conf',
            default => throw new RuntimeException('Unknown service kind.'),
        };

        File::put($directory.'/'.$filename, $service->config);

        $arguments = $service->kind === 'php-fpm'
            ? ['php-fpm', (string) $service->version]
            : [$service->kind];

        return $this->apply($arguments);
    }

    public function removeWebsite(Website $website): string
    {
        File::deleteDirectory(storage_path('app/rendered/sites/'.$website->slug));

        return $this->apply(['remove-site', $website->slug]);
    }

    public function removeTunnel(CloudflareTunnel $tunnel): string
    {
        File::deleteDirectory(storage_path('app/rendered/tunnels/'.$tunnel->slug));

        return $this->apply(['remove-tunnel', $tunnel->slug]);
    }

    public function prepareSiteDirectory(Website $website): string
    {
        return $this->apply(['mkdir-site', $website->slug]);
    }

    private function tunnelUnit(CloudflareTunnel $tunnel): string
    {
        $token = str_replace(["\n", "\r", '"'], '', (string) $tunnel->token);
        $slug = $tunnel->slug;

        return <<<UNIT
[Unit]
Description=Laravelify Cloudflare tunnel {$slug}
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/bin/cloudflared tunnel --no-autoupdate --config /etc/cloudflared/laravelify-{$slug}.yml run --token "{$token}"
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT;
    }

    private function ensurePanelCanWrite(string $directory): void
    {
        File::ensureDirectoryExists($directory);

        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! function_exists('posix_getpwnam')) {
            return;
        }

        $owner = posix_getpwnam('www-data');

        if ($owner === false) {
            return;
        }

        foreach ([$directory, dirname($directory), dirname($directory, 2)] as $path) {
            if (is_dir($path)) {
                @chown($path, $owner['name']);
                @chgrp($path, $owner['name']);
            }
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    /**
     * The last lines of this site's nginx access or error log (root-only files).
     */
    public function nginxLog(Website $website, string $kind, int $lines): string
    {
        if (! in_array($kind, ['access', 'error'], true)) {
            throw new RuntimeException('Unknown nginx log.');
        }

        return $this->apply(['nginx-log', $website->slug, $kind, (string) max(1, min($lines, 5000))]);
    }

    /**
     * Refresh apt and list upgradable packages (read-only otherwise).
     */
    public function serverPatches(): string
    {
        return $this->apply(['server-patches', 'all']);
    }

    /**
     * Register the phpMyAdmin "signon" server used by the panel's one-click sign-in.
     */
    public function phpMyAdminSignon(): string
    {
        return $this->apply(['phpmyadmin-sso', 'install']);
    }

    /**
     * Check every nginx config with nginx -t.
     */
    public function nginxTest(): string
    {
        return $this->apply(['nginx-test', 'all']);
    }

    /**
     * The last lines of nginx's own error log (/var/log/nginx/error.log, root-only).
     */
    public function nginxErrorLog(int $lines): string
    {
        return $this->apply(['nginx-global-log', 'error', (string) max(1, min($lines, 2000))]);
    }

    private function apply(array $arguments): string
    {
        $runner = app(CommandRunner::class);

        if (! $runner->supportsHostCommands()) {
            return '[skipped] host commands are disabled';
        }

        $script = base_path('scripts/host-apply.sh');
        $command = [$script, ...$arguments];

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            $command = ['sudo', '-n', $script, ...$arguments];
        }

        $result = $runner->run($command);

        if (! $result->ok) {
            throw new RuntimeException(trim($result->output) ?: 'host-apply.sh failed');
        }

        return $result->output;
    }
}
