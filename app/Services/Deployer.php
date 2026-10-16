<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Setting;
use App\Models\Website;
use App\Support\BuildOutput;
use App\Support\EnvFile;
use App\Support\GitInput;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class Deployer
{
    public function __construct(
        private CommandRunner $runner,
        private HostWriter $hostWriter,
        private DatabaseProvisioner $databases,
        private DeploymentNotifier $notifier,
        private SiteFiles $files,
        private SiteUser $site,
    ) {}

    public function run(Deployment $deployment): void
    {
        $deployment->refresh();

        if ($deployment->status === 'cancelled') {
            return;
        }

        $started = Deployment::query()
            ->whereKey($deployment->id)
            ->where('status', '!=', 'cancelled')
            ->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

        if ($started === 0) {
            return;
        }

        $this->runner->stopWhen(fn () => Deployment::query()->whereKey($deployment->id)->value('status') === 'cancelled');

        $log = new DeploymentLogger($deployment->load('website.tunnel'));

        try {
            $website = $deployment->website;

            if (! $website) {
                throw new RuntimeException('Website is missing.');
            }

            $this->actAsSiteOf($website);

            $log->line('Deploying '.$website->name.' ('.$website->type.', branch '.$website->branch.').');

            if (! $deployment->branch) {
                $deployment->update(['branch' => $website->branch]);
            }

            $this->report($log, $this->notifier->started($deployment->refresh()));

            if (blank($website->repository)) {
                throw new RuntimeException('Set a Git repository before deploying.');
            }

            if (! $this->runner->supportsHostCommands()) {
                $log->line('Host commands are disabled. Config files were rendered. Git, Composer, npm, nginx, Supervisor, and PM2 were not executed.');
                $this->hostWriter->writeWebsite($website);
                $deployment->update(['status' => 'success', 'finished_at' => now()]);
                $this->report($log, $this->notifier->succeeded($deployment->refresh()));

                return;
            }

            $this->checkout($website, $log);

            // The database comes before the install, which runs the migrations against it.
            if ($website->isPhp() && $website->provision_database) {
                $this->keepDatabaseCredentials($website, $log);
                $log->line($this->databases->create($website));
            }

            $this->writeEnv($website, $log);
            $this->install($website, $log);
            $this->syncEnvBack($website, $log);
            $log->line($this->hostWriter->writeWebsite($website));

            $sha = trim($this->must($this->runner->runAsSite(['git', '-C', $website->deploy_path, 'rev-parse', 'HEAD']), $log)->output);
            $commit = $this->runner->runAsSite(['git', '-C', $website->deploy_path, 'log', '-1', '--format=%an%n%B']);
            [$author, $message] = array_pad(explode("\n", trim($commit->output), 2), 2, '');

            $deployment->update([
                'status' => 'success',
                'commit_sha' => $sha !== '' ? $sha : $deployment->commit_sha,
                'commit_author' => $deployment->commit_author ?: ($commit->ok && $author !== '' ? mb_substr($author, 0, 190) : null),
                'commit_message' => $deployment->commit_message ?: ($commit->ok && trim($message) !== '' ? mb_substr(trim($message), 0, 2000) : null),
                'finished_at' => now(),
            ]);
            $log->line('Deploy finished in '.$deployment->durationLabel().'.');
            $this->report($log, $this->notifier->succeeded($deployment->refresh()));
        } catch (Throwable $exception) {
            if ($exception->getMessage() === 'Deploy stopped.') {
                $log->line('Deploy stopped.');
                $deployment->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                ]);
                $this->report($log, $this->notifier->cancelled($deployment->refresh()));

                return;
            }

            $log->line('ERROR: '.$exception->getMessage());
            $deployment->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);
            $this->report($log, $this->notifier->failed($deployment->refresh(), $exception->getMessage()));
        } finally {
            $this->runner->stopWhen(null);
            $this->runner->actAs(null);
        }
    }

    private function checkout(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;
        $repository = $this->authenticatedRepository($website);

        $log->line($this->hostWriter->prepareSiteDirectory($website));
        // A new site's folder (and its own user) exists only now.
        $this->actAsSiteOf($website);

        if (is_dir($path.'/.git')) {
            // Never trust the stored origin: it may hold an expired GitHub App token from
            // an earlier clone. Keep origin token-free and fetch with a fresh token each time.
            $this->must($this->runner->runAsSite(['git', '-C', $path, 'remote', 'set-url', 'origin', (string) $website->repository]), $log);
            GitInput::assertSafe((string) $website->repository, $website->branch);
            $this->must($this->runner->runAsSite([
                'git', '-C', $path, 'fetch', '--', $repository,
                '+refs/heads/'.$website->branch.':refs/remotes/origin/'.$website->branch,
            ]), $log);
            $this->must($this->runner->runAsSite(['git', '-C', $path, 'checkout', '-B', $website->branch, 'origin/'.$website->branch]), $log);
            $this->must($this->runner->runAsSite(['git', '-C', $path, 'reset', '--hard', 'origin/'.$website->branch]), $log);

            return;
        }

        // The clone is made by the site user, in its own home folder.
        $checkouts = $this->site->home().DIRECTORY_SEPARATOR.'checkouts';
        $staging = $checkouts.DIRECTORY_SEPARATOR.$website->slug;
        $this->files->delete($staging);
        $this->files->makeDirectory($checkouts, 0700, true);

        GitInput::assertSafe((string) $website->repository, $website->branch);
        $this->must($this->runner->runAsSite([
            'git', 'clone', '--branch', $website->branch, '--single-branch', '--', $repository, $staging,
        ]), $log);
        // The clone URL carries a short-lived token. Do not leave it in .git/config.
        $this->must($this->runner->runAsSite(['git', '-C', $staging, 'remote', 'set-url', 'origin', (string) $website->repository]), $log);
        // Without the owner and group: new files take the site folder's group, which the panel
        // and nginx read through.
        $this->must($this->runner->runAsSite(['cp', '-R', '-P', '--preserve=mode,timestamps,links', $staging.'/.', $path.'/']), $log);
        $this->files->delete($staging);
    }

    /**
     * Deploy commands run as the website's own user (or the shared one, for sites not moved yet).
     */
    private function actAsSiteOf(Website $website): void
    {
        $this->site = app(SiteUser::class)->for($website);
        $this->runner->actAs($this->site);
    }

    /**
     * A site with a database made by the panel always uses that database's login, even when
     * an .env from somewhere else (an old server's backup) was pasted over it.
     */
    private function keepDatabaseCredentials(Website $website, DeploymentLogger $log): void
    {
        $values = [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => (string) $website->db_name,
            'DB_USERNAME' => (string) $website->db_username,
            'DB_PASSWORD' => (string) $website->db_password,
        ];
        $content = (string) $website->env_content;
        $fixed = EnvFile::set($content, $values);

        if (EnvFile::values($content, array_keys($values)) !== EnvFile::values($fixed, array_keys($values))) {
            $website->update(['env_content' => $fixed]);
            $log->line('Set DB_* in .env to the database the panel made for this site.');
        }
    }

    private function writeEnv(Website $website, DeploymentLogger $log): void
    {
        $this->files->makeDirectory($website->deploy_path, 0755, true);
        $this->files->put($website->deploy_path.'/.env', (string) $website->env_content, 0640);
        $log->line('Wrote '.$website->deploy_path.'/.env');
    }

    private function install(Website $website, DeploymentLogger $log): void
    {
        match (true) {
            $website->isLaravel() => $this->installLaravel($website, $log),
            $website->isPhp() => $this->installPhp($website, $log),
            $website->isNextjs() => $this->installNextjs($website, $log),
            $website->isNode() => $this->installNode($website, $log),
            default => $this->installStatic($website, $log),
        };
    }

    private function installLaravel(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;
        $php = '/usr/bin/php'.($website->php_version ?: '8.4');
        $this->composerInstall($path, $log);

        // A key may be quoted ('base64:…' or "base64:…"); only a missing or empty one is replaced.
        if (preg_match('/^\s*APP_KEY\s*=\s*["\']?base64:[A-Za-z0-9+\/=]{20,}/m', (string) file_get_contents($path.'/.env')) !== 1) {
            $this->writeAppKey($path);
            $log->line('Generated APP_KEY.');
        }

        if (filled($website->db_name)) {
            $this->must($this->runner->runAsSite([$php, $path.'/artisan', 'migrate', '--force', '--no-ansi'], $path), $log);
        } else {
            $log->line('Skipped migrations. Create a MySQL database for this site before migrating.');
        }

        $this->must($this->runner->runAsSite([$php, $path.'/artisan', 'optimize', '--no-ansi'], $path), $log);
        $this->installFrontend($website, $log);
        $this->installSocketIo($website, $log);
    }

    /**
     * Plain PHP: Composer when there is a composer.json, nothing else to build.
     */
    private function installPhp(Website $website, DeploymentLogger $log): void
    {
        if (is_file($website->deploy_path.'/composer.json')) {
            $this->composerInstall($website->deploy_path, $log);
        } else {
            $log->line('No composer.json, so there is nothing to install.');
        }
    }

    private function installNextjs(Website $website, DeploymentLogger $log): void
    {
        $this->installDependencies($website, $log);
        $this->buildSite($website, $log);
        $this->prepareStandalone($website->deploy_path, $log);
    }

    /**
     * Express, NestJS, Nuxt, SvelteKit, or any Node app: install, build when the type has a
     * build step, and mark the site built so PM2 starts it (see host-apply.sh).
     */
    private function installNode(Website $website, DeploymentLogger $log): void
    {
        $this->installDependencies($website, $log);
        $this->buildSite($website, $log);

        $this->files->put($website->deploy_path.'/.laravelify-built', now()->toIso8601String()."\n");
        $log->line('Start command: '.($website->startCommand() ?: 'npm start'));
    }

    /**
     * React, Vue, Angular, Svelte, Astro: build, then point public_html at the output folder
     * that nginx serves. Static HTML has no build and is served from the repository itself.
     */
    private function installStatic(Website $website, DeploymentLogger $log): void
    {
        if ($website->type === 'static') {
            $log->line('Static HTML: no build step, the repository files are served as they are.');

            return;
        }

        $this->installDependencies($website, $log);
        $this->buildSite($website, $log);
        $this->linkBuildOutput($website, $log);
    }

    private function composerInstall(string $path, DeploymentLogger $log): void
    {
        $composer = is_executable('/usr/local/bin/composer') ? '/usr/local/bin/composer' : 'composer';
        $this->must($this->runner->runAsSite([$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-ansi', '--no-progress'], $path), $log);
    }

    /**
     * npm, pnpm, or Yarn (from the lockfile), or the install command saved on the site.
     */
    private function installDependencies(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;

        if (! is_file($path.'/package.json')) {
            throw new RuntimeException('There is no package.json in the repository. Check the repository, the branch, and the framework you chose.');
        }

        $withNode = base_path('scripts/with-node.sh');
        $node = (string) ($website->node_version ?: '22');

        if ($command = $website->installCommand()) {
            $log->line('Installing with the command saved on this site.');
            $this->runShell($withNode, $node, $path, $command, $log);

            return;
        }

        $this->installPackages($withNode, $node, $path, $log);
    }

    /**
     * The build command saved on the site, else the type's default. The default
     * "npm run build" follows the lockfile (pnpm and Yarn projects build with their own tool).
     */
    private function buildSite(Website $website, DeploymentLogger $log): void
    {
        $command = $website->buildCommand();

        if ($command === null) {
            $log->line('This type has no build step.');

            return;
        }

        $withNode = base_path('scripts/with-node.sh');
        $node = (string) ($website->node_version ?: '22');

        if (blank($website->build_command) && $command === 'npm run build') {
            $this->runScript($withNode, $node, $website->deploy_path, 'build', $log);

            return;
        }

        $this->runShell($withNode, $node, $website->deploy_path, $command, $log);
    }

    private function runShell(string $withNode, string $node, string $path, string $command, DeploymentLogger $log): void
    {
        $this->must($this->runner->runAsSite(['bash', $withNode, $node, 'bash', '-c', $command], $path), $log);
    }

    private function linkBuildOutput(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;
        $candidates = (array) ($website->definition()['output'] ?? ['dist']);
        $output = BuildOutput::locate($path, $website->public_dir, $candidates);

        if ($output === null) {
            $looked = filled($website->public_dir) ? (string) $website->public_dir : implode(', ', $candidates);

            throw new RuntimeException('The build finished, but no output folder with files was found (looked in: '.$looked.'). Set the output folder on the Runtime tab.');
        }

        $link = $path.'/public_html';

        if (is_dir($link) && ! is_link($link)) {
            throw new RuntimeException('public_html is a real folder in your repository. Rename it, because Laravelify uses that name for the build output.');
        }

        $this->files->symlink($output, $link);

        $log->line('Serving '.$output.' (public_html → '.$output.').');
    }

    private function installFrontend(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;

        if (! is_file($path.'/package.json')) {
            return;
        }

        $withNode = base_path('scripts/with-node.sh');
        $node = (string) ($website->node_version ?: '22');
        $this->files->delete($path.'/node_modules');
        $this->installPackages($withNode, $node, $path, $log);
        $this->runScript($withNode, $node, $path, 'build', $log);
    }

    /**
     * The package manager the project uses, from its lockfile.
     */
    private function packageManager(string $path): string
    {
        return match (true) {
            is_file($path.'/pnpm-lock.yaml') => 'pnpm',
            is_file($path.'/yarn.lock') => 'yarn',
            default => 'npm',
        };
    }

    private function installPackages(string $withNode, string $node, string $path, DeploymentLogger $log): void
    {
        $manager = $this->packageManager($path);
        $log->line('Installing packages with '.$manager.'.');

        match ($manager) {
            // Corepack runs the pnpm/yarn version pinned in package.json "packageManager".
            'pnpm' => $this->must($this->runner->runAsSite(['bash', $withNode, $node, 'corepack', 'pnpm', 'install', '--frozen-lockfile'], $path), $log),
            'yarn' => $this->must($this->runner->runAsSite(['bash', $withNode, $node, 'corepack', 'yarn', 'install'], $path), $log),
            default => $this->npm($withNode, $node, $path, [is_file($path.'/package-lock.json') ? 'ci' : 'install'], $log),
        };
    }

    private function runScript(string $withNode, string $node, string $path, string $script, DeploymentLogger $log): void
    {
        match ($this->packageManager($path)) {
            'pnpm' => $this->must($this->runner->runAsSite(['bash', $withNode, $node, 'corepack', 'pnpm', 'run', $script], $path), $log),
            'yarn' => $this->must($this->runner->runAsSite(['bash', $withNode, $node, 'corepack', 'yarn', 'run', $script], $path), $log),
            default => $this->npm($withNode, $node, $path, ['run', $script], $log),
        };
    }

    /**
     * next build with output: "standalone" leaves out public/ and .next/static/.
     * Copy them, and the .env for server-side code, next to server.js so PM2 serves
     * the complete site.
     */
    private function prepareStandalone(string $path, DeploymentLogger $log): void
    {
        $standalone = $path.'/.next/standalone';

        if (! is_file($standalone.'/server.js')) {
            throw new RuntimeException('next build did not create .next/standalone/server.js. Add output: "standalone" to next.config so PM2 can start the app.');
        }

        $this->files->delete($standalone.'/public');
        $this->files->delete($standalone.'/.next/static');

        if (is_dir($path.'/public')) {
            $this->files->copy($path.'/public', $standalone.'/public');
        }

        if (is_dir($path.'/.next/static')) {
            $this->files->makeDirectory($standalone.'/.next', 0755, true);
            $this->files->copy($path.'/.next/static', $standalone.'/.next/static');
        }

        if (is_file($path.'/.env')) {
            $this->files->copy($path.'/.env', $standalone.'/.env');
        }

        $log->line('Copied public/, .next/static/, and .env into .next/standalone.');
    }

    private function installSocketIo(Website $website, DeploymentLogger $log): void
    {
        $realtime = $website->deploy_path.'/realtime';

        if (! is_file($realtime.'/package.json')) {
            return;
        }

        $withNode = base_path('scripts/with-node.sh');
        $install = is_file($realtime.'/package-lock.json') ? 'ci' : 'install';
        $this->files->delete($realtime.'/node_modules');
        $this->npm($withNode, '22', $realtime, [$install, '--omit=dev'], $log);

        if (app(ConfigRenderer::class)->attachSocketIo($website)) {
            $log->line('Added the Socket.IO proxy on /socket.io/ and a Supervisor program for realtime/server.js.');
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function npm(string $withNode, string $node, string $path, array $arguments, DeploymentLogger $log): void
    {
        $this->must($this->runner->runAsSite(['bash', $withNode, $node, 'npm', '--no-progress', '--no-fund', '--loglevel=warn', ...$arguments], $path), $log);
    }

    private function writeAppKey(string $path): void
    {
        $envPath = $path.'/.env';
        $contents = (string) file_get_contents($envPath);
        $key = 'base64:'.base64_encode(random_bytes(32));
        $contents = preg_match('/^APP_KEY=.*$/m', $contents)
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents, 1)
            : "APP_KEY={$key}\n".$contents;

        $this->files->put($envPath, $contents);
    }

    private function syncEnvBack(Website $website, DeploymentLogger $log): void
    {
        $envPath = $website->deploy_path.'/.env';

        if (! is_file($envPath)) {
            return;
        }

        $website->env_content = File::get($envPath);
        $website->save();
        $log->line('Saved the environment file back to the panel.');
    }

    private function authenticatedRepository(Website $website): string
    {
        $repository = (string) $website->repository;
        $token = null;

        if ($website->githubApp?->isConnected()) {
            $token = app(GithubAppClient::class)->installationToken($website->githubApp);
        }

        $token ??= Setting::getValue('github_token');

        if (! $token) {
            return $repository;
        }

        if (preg_match('#^https://github\.com/(.+)$#', $repository, $matches)) {
            return 'https://x-access-token:'.rawurlencode($token).'@github.com/'.$matches[1];
        }

        return $repository;
    }

    /**
     * Note a failed Telegram notification in the deploy log. It never fails the deploy.
     */
    private function report(DeploymentLogger $log, ?string $notificationError): void
    {
        if ($notificationError !== null) {
            $log->line('Telegram notification failed: '.$notificationError);
        }
    }

    private function must(CommandResult $result, DeploymentLogger $log): CommandResult
    {
        $log->line('$ '.$result->commandLine());

        if ($result->output !== '') {
            $log->line($result->output);
        }

        if ($result->stopped) {
            throw new RuntimeException('Deploy stopped.');
        }

        if (! $result->ok) {
            throw new RuntimeException('Command failed with exit '.$result->exitCode.'.');
        }

        return $result;
    }
}
