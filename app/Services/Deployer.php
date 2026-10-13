<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Setting;
use App\Models\Website;
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
            $this->writeEnv($website, $log);
            $this->install($website, $log);
            $this->syncEnvBack($website, $log);
            $log->line($this->hostWriter->writeWebsite($website));

            if ($website->isLaravel() && $website->provision_database) {
                $log->line($this->databases->create($website));
            }

            $sha = trim($this->must($this->runner->run(['git', '-C', $website->deploy_path, 'rev-parse', 'HEAD']), $log)->output);
            $commit = $this->runner->run(['git', '-C', $website->deploy_path, 'log', '-1', '--format=%an%n%B']);
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
        }
    }

    private function checkout(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;
        $repository = $this->authenticatedRepository($website);

        $log->line($this->hostWriter->prepareSiteDirectory($website));

        if (is_dir($path.'/.git')) {
            // Never trust the stored origin: it may hold an expired GitHub App token from
            // an earlier clone. Keep origin token-free and fetch with a fresh token each time.
            $this->must($this->runner->run(['git', '-C', $path, 'remote', 'set-url', 'origin', (string) $website->repository]), $log);
            $this->must($this->runner->run([
                'git', '-C', $path, 'fetch', $repository,
                '+refs/heads/'.$website->branch.':refs/remotes/origin/'.$website->branch,
            ]), $log);
            $this->must($this->runner->run(['git', '-C', $path, 'checkout', '-B', $website->branch, 'origin/'.$website->branch]), $log);
            $this->must($this->runner->run(['git', '-C', $path, 'reset', '--hard', 'origin/'.$website->branch]), $log);

            return;
        }

        $staging = storage_path('app/checkouts/'.$website->slug);
        File::deleteDirectory($staging);
        File::ensureDirectoryExists(storage_path('app/checkouts'));

        $this->must($this->runner->run([
            'git', 'clone', '--branch', $website->branch, '--single-branch', $repository, $staging,
        ]), $log);
        // The clone URL carries a short-lived token. Do not leave it in .git/config.
        $this->must($this->runner->run(['git', '-C', $staging, 'remote', 'set-url', 'origin', (string) $website->repository]), $log);
        $this->must($this->runner->run(['cp', '-a', $staging.'/.', $path.'/']), $log);
        File::deleteDirectory($staging);
    }

    private function writeEnv(Website $website, DeploymentLogger $log): void
    {
        File::ensureDirectoryExists($website->deploy_path);
        File::put($website->deploy_path.'/.env', (string) $website->env_content);
        $log->line('Wrote '.$website->deploy_path.'/.env');
    }

    private function install(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;

        if ($website->isLaravel()) {
            $php = '/usr/bin/php'.($website->php_version ?: '8.4');
            $composer = is_executable('/usr/local/bin/composer') ? '/usr/local/bin/composer' : 'composer';
            $this->must($this->runner->run([$composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-ansi', '--no-progress'], $path), $log);

            if (! str_contains((string) file_get_contents($path.'/.env'), 'APP_KEY=base64:')) {
                $this->writeAppKey($path);
                $log->line('Generated APP_KEY.');
            }

            if (filled($website->db_name)) {
                $this->must($this->runner->run([$php, $path.'/artisan', 'migrate', '--force', '--no-ansi'], $path), $log);
            } else {
                $log->line('Skipped migrations. Create a MySQL database for this site before migrating.');
            }

            $this->must($this->runner->run([$php, $path.'/artisan', 'optimize', '--no-ansi'], $path), $log);
            $this->installFrontend($website, $log);
            $this->installSocketIo($website, $log);

            return;
        }

        $withNode = base_path('scripts/with-node.sh');
        $node = (string) ($website->node_version ?: '22');
        $this->installPackages($withNode, $node, $path, $log);
        $this->runScript($withNode, $node, $path, 'build', $log);
        $this->prepareStandalone($path, $log);
    }

    private function installFrontend(Website $website, DeploymentLogger $log): void
    {
        $path = $website->deploy_path;

        if (! is_file($path.'/package.json')) {
            return;
        }

        $withNode = base_path('scripts/with-node.sh');
        $node = (string) ($website->node_version ?: '22');
        File::deleteDirectory($path.'/node_modules');
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
            'pnpm' => $this->must($this->runner->run(['bash', $withNode, $node, 'corepack', 'pnpm', 'install', '--frozen-lockfile'], $path), $log),
            'yarn' => $this->must($this->runner->run(['bash', $withNode, $node, 'corepack', 'yarn', 'install'], $path), $log),
            default => $this->npm($withNode, $node, $path, [is_file($path.'/package-lock.json') ? 'ci' : 'install'], $log),
        };
    }

    private function runScript(string $withNode, string $node, string $path, string $script, DeploymentLogger $log): void
    {
        match ($this->packageManager($path)) {
            'pnpm' => $this->must($this->runner->run(['bash', $withNode, $node, 'corepack', 'pnpm', 'run', $script], $path), $log),
            'yarn' => $this->must($this->runner->run(['bash', $withNode, $node, 'corepack', 'yarn', 'run', $script], $path), $log),
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

        File::deleteDirectory($standalone.'/public');
        File::deleteDirectory($standalone.'/.next/static');

        if (is_dir($path.'/public')) {
            File::copyDirectory($path.'/public', $standalone.'/public');
        }

        if (is_dir($path.'/.next/static')) {
            File::ensureDirectoryExists($standalone.'/.next');
            File::copyDirectory($path.'/.next/static', $standalone.'/.next/static');
        }

        if (is_file($path.'/.env')) {
            File::copy($path.'/.env', $standalone.'/.env');
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
        File::deleteDirectory($realtime.'/node_modules');
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
        $this->must($this->runner->run(['bash', $withNode, $node, 'npm', '--no-progress', '--no-fund', '--loglevel=warn', ...$arguments], $path), $log);
    }

    private function writeAppKey(string $path): void
    {
        $envPath = $path.'/.env';
        $contents = (string) file_get_contents($envPath);
        $key = 'base64:'.base64_encode(random_bytes(32));
        $contents = preg_match('/^APP_KEY=.*$/m', $contents)
            ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents, 1)
            : "APP_KEY={$key}\n".$contents;

        file_put_contents($envPath, $contents);
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
