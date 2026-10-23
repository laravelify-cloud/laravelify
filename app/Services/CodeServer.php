<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * VS Code in the browser for a website: code-server (MIT), started as the website's own user in
 * the same sandbox as its terminal, on its workspace (the editor's clone of the repository). It
 * listens on 127.0.0.1 only; nginx serves it at <panel>/code/<token>/ and asks the panel before
 * every request, so only a signed-in panel user reaches it. It stops itself after 30 minutes
 * without use (code-server's --idle-timeout-seconds) and frees its memory.
 *
 * git in the editor pushes with a GitHub App token that reaches only this repository; it lasts
 * an hour and is written again on every start and every 30 minutes while the editor runs.
 */
class CodeServer
{
    public function __construct(
        private HostWriter $host,
        private ProjectWorkspace $workspace,
        private PortAllocator $ports,
        private SiteFiles $files,
        private SiteUser $sites,
        private GithubAppClient $github,
        private LiveServer $live,
    ) {}

    public function url(Website $website): ?string
    {
        return $website->code_server_token
            ? rtrim((string) config('app.url'), '/').'/code/'.$website->code_server_token.'/?folder='.rawurlencode($this->workspace->path($website))
            : null;
    }

    /**
     * Why VS Code cannot run for this website, or null when it can.
     */
    public function unavailableReason(Website $website): ?string
    {
        if (! $this->workspace->supports($website)) {
            return 'VS Code needs a website that deploys from a GitHub repository or an example project.';
        }

        if (! $this->sites->for($website)->isOwn()) {
            return 'VS Code runs as the website\'s own user. Move the website to its own user first (Security tab).';
        }

        return null;
    }

    /**
     * Install code-server when needed, prepare the workspace, and start (or restart) the editor.
     *
     * @return array{url: ?string, installing: ?int, total?: int} The editor's address; or, while code-server
     *                                                            is still downloading the first time, the MB so far (of total).
     */
    public function start(Website $website, string $author): array
    {
        if (($reason = $this->unavailableReason($website)) !== null) {
            throw new RuntimeException($reason);
        }

        // Already running: open it at once (a fresh git token, nothing pulled or restarted).
        if ($website->code_server_token && $website->code_server_port && $this->status($website)['running']) {
            $this->writeGitCredentials($website, $author);
            $this->writeLiveServerConfig($website);

            return ['url' => (string) $this->url($website), 'installing' => null];
        }

        $install = $this->host->codeServerInstall();

        if ($install !== 'installed' && ! str_starts_with($install, '[skipped]')) {
            $parts = explode(' ', $install);

            return ['url' => null, 'installing' => (int) ($parts[1] ?? 0), 'total' => (int) ($parts[2] ?? 0)];
        }

        $this->workspace->prepare($website);

        $website->forceFill([
            'code_server_port' => $website->code_server_port ?: $this->ports->random(13000, 13999),
            'code_server_token' => $website->code_server_token ?: Str::lower(Str::random(32)),
        ])->save();

        $this->writeGitCredentials($website, $author);
        $this->writeLiveServerConfig($website);
        $this->prepareProfile($website);
        $this->host->codeServerStart($website, (int) $website->code_server_port, (string) $website->code_server_token, $this->workspace->path($website));
        $this->waitForPort((int) $website->code_server_port);

        return ['url' => (string) $this->url($website), 'installing' => null];
    }

    public function stop(Website $website): void
    {
        $this->host->codeServerStop($website);
        $this->live->stop($website);
    }

    /**
     * @return array{running: bool, idle_seconds: ?int}
     */
    public function status(Website $website): array
    {
        $line = trim($this->host->codeServerStatus($website));
        [$state, $age] = array_pad(explode(' ', $line), 2, '-1');

        return ['running' => $state === 'active', 'idle_seconds' => (int) $age >= 0 ? (int) $age : null];
    }

    /**
     * A fresh token for git in the running editors (called every 30 minutes). An editor that
     * stopped by itself (idle) leaves no Live Server address behind.
     */
    public function refreshCredentials(Website $website): void
    {
        if ($this->status($website)['running']) {
            $this->writeGitCredentials($website, null);
        } elseif ($website->live_host) {
            $this->live->stop($website);
        }
    }

    /**
     * Where the editor's Live Server asks for a public address, and with which key: the panel
     * over the server's own address (no trip through Cloudflare), in a file only the site's user reads.
     */
    private function writeLiveServerConfig(Website $website): void
    {
        $user = $this->sites->for($website);
        $config = json_encode([
            'endpoint' => 'http://127.0.0.1/hooks/live/'.$website->slug,
            'host' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            'key' => $this->live->key($website),
            'ports' => LiveServer::PORTS,
        ], JSON_UNESCAPED_SLASHES);

        $dir = $user->home().'/.laravelify';
        $this->files->exec(['mkdir', '-p', '-m', '0700', $dir], null, null, $user);
        $this->files->exec(['/bin/sh', '-c', 'umask 077 && cat > "$1"', 'live', $dir.'/live.json'], (string) $config, null, $user);
    }

    /**
     * git in the editor asks a credential helper; it reads a token file in the site user's home.
     */
    private function writeGitCredentials(Website $website, ?string $author): void
    {
        $app = $website->githubApp;
        $user = $this->sites->for($website);
        $token = $app?->isConnected()
            ? $this->github->installationToken($app, GithubAppClient::fullName((string) $website->repository))
            : null;

        $dir = $user->home().'/.laravelify';
        $this->files->exec(['mkdir', '-p', '-m', '0700', $dir], null, null, $user);
        $this->files->exec(['/bin/sh', '-c', 'umask 077 && cat > "$1"', 'token', $dir.'/git-token'], (string) $token, null, $user);

        $helper = '!f() { test "$1" = get || exit 0; echo username=x-access-token; echo "password=$(cat "$HOME/.laravelify/git-token")"; }; f';
        // Commands run as the site user without HOME, so its global git config is named by path.
        $config = $user->home().'/.gitconfig';
        $commands = [['git', 'config', '--file', $config, 'credential.https://github.com.helper', $helper]];

        if ($author !== null && trim($author) !== '') {
            $commands[] = ['git', 'config', '--file', $config, 'user.name', trim($author)];
            $commands[] = ['git', 'config', '--file', $config, 'user.email', 'editor@laravelify.local'];
        }

        foreach ($commands as $command) {
            $this->files->exec($command, null, null, $user);
        }
    }

    /** Settings every editor starts with; a user's own choices are never replaced. */
    public const DEFAULT_SETTINGS = [
        // The built-in Chat works only with GitHub Copilot, which code-server cannot have.
        'chat.disableAIFeatures' => true,
        // No welcome page (code-server's shows an advertisement for Coder).
        'workbench.startupEditor' => 'none',
        'workbench.welcomePage.walkthroughs.openOnInstall' => false,
        'telemetry.telemetryLevel' => 'off',
        'extensions.ignoreRecommendations' => true,
        // Dark by default; a theme the person picks is kept.
        'workbench.colorTheme' => 'Default Dark Modern',
        // The AI chat's "Add to Chat · Quick Edit" links above a selection are CodeLenses.
        'editor.codeLens' => true,
    ];

    /**
     * The editor's own setup for the website user: the Laravelify AI extension (Claude Code
     * and Cursor Agent sign-in and panels) and the default settings.
     */
    private function prepareProfile(Website $website): void
    {
        $user = $this->sites->for($website);
        $home = $user->home();
        $manifest = json_decode((string) file_get_contents(resource_path('code-server/laravelify-ai/package.json')), true);
        $folder = $home.'/.code-server/extensions/laravelify.laravelify-ai-'.$manifest['version'];

        if (! $this->files->exec(['test', '-d', $folder], null, null, $user)->ok) {
            $vsix = $this->extensionPackage();
            $target = $home.'/.laravelify/laravelify-ai.vsix';
            $this->files->exec(['/bin/sh', '-c', 'mkdir -p "$(dirname "$1")" && cat > "$1"', 'copy', $target], (string) file_get_contents($vsix), null, $user);
            $this->files->exec([
                '/usr/bin/env', 'HOME='.$home, '/opt/code-server/bin/code-server',
                '--user-data-dir', $home.'/.code-server/data', '--extensions-dir', $home.'/.code-server/extensions',
                '--install-extension', $target, '--force',
            ], null, null, $user);
            // Older versions of the extension stay on disk otherwise.
            $this->files->exec(['/bin/sh', '-c', 'for d in "$1"/laravelify.laravelify-ai-*; do [ "$d" = "$2" ] || rm -rf -- "$d"; done', 'clean', $home.'/.code-server/extensions', $folder], null, null, $user);
        }

        $settingsFile = $home.'/.code-server/data/User/settings.json';
        $current = $this->files->exec(['cat', $settingsFile], null, null, $user);
        $settings = $current->ok ? json_decode($current->output, true) : [];

        // A settings file with comments (JSONC) is the user's own: it is left as it is.
        if (! is_array($settings)) {
            return;
        }

        $merged = $settings + self::DEFAULT_SETTINGS;

        if ($merged !== $settings || ! $current->ok) {
            $this->files->exec(
                ['/bin/sh', '-c', 'mkdir -p "$(dirname "$1")" && cat > "$1"', 'settings', $settingsFile],
                json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
                null,
                $user,
            );
        }
    }

    /**
     * resources/code-server/laravelify-ai packed as a .vsix (a zip VS Code installs), made once
     * per version in the panel's storage.
     */
    public function extensionPackage(): string
    {
        $source = resource_path('code-server/laravelify-ai');
        $manifest = json_decode((string) file_get_contents($source.'/package.json'), true);
        $version = (string) $manifest['version'];
        $target = storage_path('app/code-server/laravelify-ai-'.$version.'.vsix');

        if (is_file($target)) {
            return $target;
        }

        File::ensureDirectoryExists(dirname($target));
        $zip = new ZipArchive;
        $zip->open($target.'.tmp', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="utf-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension=".json" ContentType="application/json"/><Default Extension=".js" ContentType="application/javascript"/><Default Extension=".svg" ContentType="image/svg+xml"/><Default Extension=".vsixmanifest" ContentType="text/xml"/></Types>');
        $zip->addFromString('extension.vsixmanifest', sprintf(
            '<?xml version="1.0" encoding="utf-8"?><PackageManifest Version="2.0.0" xmlns="http://schemas.microsoft.com/developer/vsx-schema/2011"><Metadata><Identity Language="en-US" Id="%s" Version="%s" Publisher="%s"/><DisplayName>%s</DisplayName><Description xml:space="preserve">%s</Description><Categories>AI,Other</Categories><Properties><Property Id="Microsoft.VisualStudio.Code.Engine" Value="%s"/></Properties></Metadata><Installation><InstallationTarget Id="Microsoft.VisualStudio.Code"/></Installation><Dependencies/><Assets><Asset Type="Microsoft.VisualStudio.Code.Manifest" Path="extension/package.json" Addressable="true"/></Assets></PackageManifest>',
            $manifest['name'], $version, $manifest['publisher'], htmlspecialchars((string) $manifest['displayName']), htmlspecialchars((string) $manifest['description']), $manifest['engines']['vscode'],
        ));

        foreach (File::allFiles($source) as $file) {
            $zip->addFile($file->getPathname(), 'extension/'.str_replace('\\', '/', $file->getRelativePathname()));
        }

        $zip->close();
        rename($target.'.tmp', $target);

        return $target;
    }

    private function waitForPort(int $port): void
    {
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.5);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(250000);
        }

        throw new RuntimeException('VS Code did not start within 10 seconds. Try again; the website\'s logs may say why.');
    }
}
