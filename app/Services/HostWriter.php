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

        return $this->apply(['site', $website->slug, $website->type, (string) ($website->php_version ?: '8.4')]);
    }

    /**
     * Only a site's workers: its Supervisor programs or its PM2 app. Used by updates; the
     * site's .env and nginx files are left as they are.
     */
    public function writeProcesses(Website $website): string
    {
        $this->renderSite($website);

        return $this->apply(['site-processes', $website->slug, $website->type]);
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

        // A new site gets its folder (and its own user) first: the files below follow its user.
        if (! is_dir((string) $website->deploy_path) && app(SiteUser::class)->separate()) {
            $this->prepareSiteDirectory($website);
        }

        $user = app(SiteUser::class)->for($website);

        // Every site includes its security snippet: it also carries the Monitoring request log.
        if ($security->ensureInclude($website)) {
            $website->save();
        }

        // Supervisor programs run as the site user and log outside the site folder (host-apply.sh
        // refuses anything else). Older configs are brought up to date here and saved.
        if ($website->isLaravel()) {
            $secured = ConfigRenderer::secureSupervisor((string) $website->process_config, $website->slug, $user->name(), $user->isOwn() ? $user->home() : null);

            if (trim($secured) !== trim((string) $website->process_config)) {
                $website->process_config = $secured;
                $website->save();
            }
        }

        // PHP runs in the site's own PHP-FPM pool once it has its own user.
        if ($website->isPhp()) {
            $nginx = ConfigRenderer::withSiteSocket((string) $website->nginx_config, $website, $user->isOwn());

            if ($nginx !== (string) $website->nginx_config) {
                $website->nginx_config = $nginx;
                $website->save();
            }
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

    /**
     * Move a website to its own Unix user (lfy-<slug>): its files, PHP-FPM pool, workers, and PM2
     * app. The items of the shared trash that came from this site move to the site's own trash.
     *
     * @param  list<int>  $trashIds
     */
    public function separateSite(Website $website, array $trashIds = []): string
    {
        return $this->apply(['site-user', $website->slug, ...array_map('strval', $trashIds)]);
    }

    /**
     * Give a website back to the shared user (www-data), for scripts/rollback-site-users.sh.
     */
    public function unseparateSite(Website $website): string
    {
        return $this->apply(['site-user-drop', $website->slug]);
    }

    /**
     * The folder in the sites root that restores of folder backups are written to.
     */
    public function restoreFolder(): string
    {
        return $this->apply(['restore-folder', 'make']);
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

        // Run as root (an artisan command during an update): hand the folders to the panel user.
        $owner = posix_getpwuid((int) @fileowner(storage_path('app')));

        if ($owner === false || $owner['name'] === 'root') {
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
     * Empty one of a site's Supervisor program logs (root's files in /var/log/laravelify/sites).
     */
    public function clearProgramLog(Website $website, string $file): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]+\.log$/', $file) !== 1) {
            throw new RuntimeException('Unknown log.');
        }

        return $this->apply(['program-log-clear', $website->slug, $file]);
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
     * Make a folder outside /var/www that the panel can write backups into (owned by the panel user).
     */
    public function backupFolder(string $path): string
    {
        return $this->apply(['backup-folder', $path]);
    }

    /**
     * Check every nginx config with nginx -t.
     */
    /**
     * Open a website at <panel>/p/<token>/, proxied to the site's own nginx server.
     */
    public function writePanelPreview(Website $website, string $token, int $port, string $host): string
    {
        return $this->apply(['panel-preview', $website->slug, $token, (string) $port, $host]);
    }

    /**
     * Install code-server once for the server, in the background (a download of about 120 MB):
     * "installed", "installing <MB>", or "started".
     */
    public function codeServerInstall(): string
    {
        return is_file('/opt/code-server/bin/code-server') && is_file('/etc/nginx/laravelify-panel.d/00-code-auth.conf') && is_file('/opt/graphify/bin/graphify')
            ? 'installed'
            : trim($this->apply(['code-server-install-start', 'all']));
    }

    public function codeServerStart(Website $website, int $port, string $token, string $workspace): string
    {
        return $this->apply(['code-server-start', $website->slug, (string) $port, $token, $workspace]);
    }

    /**
     * Give every website the newest Claude, Cursor, and Copilot sign-in (one sign-in for all).
     */
    public function syncAgentSignIns(): string
    {
        return $this->apply(['agent-auth-sync']);
    }

    /**
     * Start the panel's queue worker and scheduler again (Supervisor gives up after quick failures).
     */
    public function startPanelWorker(): string
    {
        return $this->apply(['panel-worker-start']);
    }

    /**
     * One editor for several websites' projects, as the workspace's own user (see host-apply.sh).
     *
     * @param  list<string>  $slugs
     */
    public function workspaceStart(string $key, int $port, string $token, array $slugs): string
    {
        return $this->apply(['workspace-start', $key, (string) $port, $token, ...$slugs]);
    }

    /** The panel AI chat's own user and home (see host-apply.sh ai-chat-user). */
    public function aiChatUser(): string
    {
        return $this->apply(['ai-chat-user', 'panel']);
    }

    public function workspaceStop(string $key): string
    {
        return $this->apply(['workspace-stop', $key]);
    }

    public function workspaceStatus(string $key): string
    {
        return $this->apply(['workspace-status', $key]);
    }

    /**
     * Stop a workspace's editor and remove its user and its access to these projects.
     *
     * @param  list<string>  $slugs
     */
    public function workspaceDrop(string $key, array $slugs): string
    {
        return $this->apply(['workspace-drop', $key, ...$slugs]);
    }

    /**
     * The projects' code in one zip in the panel's storage; returns its path.
     *
     * @param  list<string>  $slugs
     */
    public function workspaceZip(string $name, array $slugs): string
    {
        return $this->apply(['workspace-zip', $name, ...$slugs]);
    }

    public function codeServerStop(Website $website): string
    {
        return $this->apply(['code-server-stop', $website->slug]);
    }

    public function codeServerStatus(Website $website): string
    {
        return $this->apply(['code-server-status', $website->slug]);
    }

    public function removePanelPreview(Website $website): string
    {
        return $this->apply(['panel-preview-remove', $website->slug]);
    }

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
