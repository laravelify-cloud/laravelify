<?php

namespace App\Services;

use App\Models\GithubApp;
use App\Models\Website;
use App\Support\GitInput;
use App\Support\SentrySetup;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Put the Sentry SDK in a website's code with one click: connect it to GlitchTip, clone its
 * repository as the site user, add the package and its setup (SentrySetup), commit, push, and
 * deploy. Only the package files change for the package (npm --package-lock-only, composer
 * --no-install), so nothing is built here: the deploy does that.
 */
class GlitchtipSdk
{
    public function __construct(
        private Glitchtip $glitchtip,
        private CommandRunner $runner,
        private SiteFiles $files,
    ) {}

    /** @return array{message: string} */
    public function setUp(Website $website): array
    {
        if (! $this->glitchtip->installed()) {
            throw new RuntimeException('Turn on error tracking first.');
        }

        if (blank($website->repository)) {
            throw new RuntimeException('This website has no Git repository to add the SDK to.');
        }

        GitInput::assertSafe((string) $website->repository, $website->branch);

        if ($website->glitchtip_project === null) {
            $this->glitchtip->connect($website);
            $website->refresh();
        }

        $site = app(SiteUser::class)->for($website);
        $this->runner->actAs($site);
        $work = $site->home().'/glitchtip-setup/'.$website->slug;
        $pushUrl = $this->pushUrl($website);

        try {
            if ($this->exists($site, $work)) {
                $this->files->delete($work);
            }

            $this->files->makeDirectory(dirname($work), 0700, true);
            $this->git(['clone', '-q', '--depth', '1', '--branch', $website->branch, '--single-branch', '--', $pushUrl, $work], null, $pushUrl, $website);

            $changed = SentrySetup::apply(
                $website,
                $this->glitchtip->dsn($website),
                fn (string $file) => $this->read($site, $work.'/'.$file),
                fn (string $file, string $contents) => $this->files->put($work.'/'.$file, $contents),
                fn (string $path) => $this->exists($site, $work.'/'.$path),
                fn (string $folder) => $this->list($work, $folder),
            );
            $installed = $this->install($website, $site, $work);

            $this->git(['add', '-A'], $work, $pushUrl, $website);

            if ($this->runner->runAsSite(['git', '-C', $work, 'diff', '--cached', '--quiet'])->ok) {
                return ['message' => 'The Sentry SDK was already in '.$website->name.'\'s code. Deploy it if the errors do not show yet.'];
            }

            $this->git(['-c', 'user.name=Laravelify', '-c', 'user.email=laravelify@localhost', 'commit', '-q', '-m', 'Send errors to GlitchTip (Sentry SDK)'], $work, $pushUrl, $website);

            // An example's repository has its branch checked out: let the push update it, as VS Code's do.
            if (app(ExampleRepositories::class)->isExample($website)) {
                $this->git(['config', 'receive.denyCurrentBranch', 'updateInstead'], (string) $website->repository, $pushUrl, $website);
            }

            $this->git(['push', '-q', '--', $pushUrl, 'HEAD:refs/heads/'.$website->branch], $work, $pushUrl, $website);
        } finally {
            try {
                if ($this->exists($site, $work)) {
                    $this->files->delete($work);
                }
            } catch (Throwable) {
                // A leftover clone is removed by the next setup.
            }

            $this->runner->actAs(null);
        }

        // A push to an example's repository (or a GitHub webhook) may have started the deploy already.
        $website->refresh();
        $deploying = $website->queueDeployment('glitchtip') !== null || $website->hasActiveDeployment();
        $files = implode(', ', array_values(array_unique([...$changed, ...$installed])));
        // Only the key in the code changed: GlitchTip was turned off and on again since the setup.
        $what = $installed === [] && $changed === [SentrySetup::keyFile($website)]
            ? 'Put the current GlitchTip key in '.$website->name.'\'s code ('.$files.')'
            : 'Added the Sentry SDK to '.$website->name.' ('.$files.')';

        return ['message' => $what.', committed and pushed to '.$website->branch.'. '
            .($deploying ? 'Deploying now: errors show in GlitchTip after that.' : 'Deploy it to start sending errors.')];
    }

    /**
     * The URL to clone and push with. Deploys only read, so a website's own GitHub App may be
     * read-only: another connected App that may push (Contents: read and write) and reaches the
     * repository is used then. Without one, the website's own token (the push then says why).
     */
    private function pushUrl(Website $website): string
    {
        $fullName = GithubAppClient::fullName((string) $website->repository);

        if ($fullName === null || preg_match('#^https://github\.com/#', (string) $website->repository) !== 1) {
            return Deployer::repositoryWithToken($website);
        }

        $github = app(GithubAppClient::class);
        $apps = GithubApp::query()->get()
            ->filter(fn (GithubApp $app) => $app->isConnected())
            ->sortByDesc(fn (GithubApp $app) => $app->id === $website->github_app_id);

        foreach ($apps as $app) {
            try {
                if ($github->canPush($app)) {
                    $token = $github->installationToken($app, $fullName);

                    return 'https://x-access-token:'.rawurlencode($token).'@github.com/'.$fullName.'.git';
                }
            } catch (Throwable) {
                // Not installed on this repository, or GitHub did not answer: try the next one.
            }
        }

        return Deployer::repositoryWithToken($website);
    }

    /**
     * Add the SDK package to the package files only. Returns the files that changed.
     *
     * @return list<string>
     */
    private function install(Website $website, SiteUser $site, string $work): array
    {
        $package = SentrySetup::package((string) $website->type);

        if ($package === null) {
            return [];
        }

        if ($package['manager'] === 'composer') {
            // The website's own PHP picks the versions (the server's default may be newer). A package
            // already there is resolved again for it, which also repairs a lock made for a newer PHP.
            $present = str_contains((string) $this->read($site, $work.'/composer.json'), '"'.$package['package'].'"');
            $command = $present
                ? ['update', $package['package'], '--with-all-dependencies']
                : ['require', $package['package']];
            $this->must($this->runner->runAsSite($this->in($work, [...Deployer::composer($website), ...$command, '--no-install', '--no-scripts', '--no-interaction', '--no-ansi', '--no-progress'])), 'composer '.$command[0].' '.$package['package']);

            return $present ? ['composer.lock'] : ['composer.json', 'composer.lock'];
        }

        if ($this->exists($site, $work.'/pnpm-lock.yaml') || $this->exists($site, $work.'/yarn.lock')) {
            throw new RuntimeException('This project uses '.($this->exists($site, $work.'/pnpm-lock.yaml') ? 'pnpm' : 'Yarn').'. Add '.$package['package'].' with it by hand, then click again to add its setup.');
        }

        if (str_contains((string) $this->read($site, $work.'/package.json'), '"'.$package['package'].'"')) {
            return [];
        }

        $this->must($this->runner->runAsSite($this->in($work, [
            'bash', base_path('scripts/with-node.sh'), (string) ($website->node_version ?: '22'),
            'npm', 'install', '--package-lock-only', '--no-audit', '--no-fund', '--loglevel=error', $package['package'],
        ])), 'npm install '.$package['package']);

        return ['package.json', 'package-lock.json'];
    }

    /**
     * A command that starts in the clone. The clone is in the site user's own home, which the
     * panel cannot enter, so the site user changes into it, not the panel.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    private function in(string $work, array $command): array
    {
        return ['/bin/sh', '-c', 'cd "$0" && exec "$@"', $work, ...$command];
    }

    /**
     * Run git as the site user. The push URL may carry a token: it never shows in a message.
     *
     * @param  list<string>  $arguments
     */
    private function git(array $arguments, ?string $work, string $pushUrl, Website $website): void
    {
        $result = $this->runner->runAsSite($work === null ? ['git', ...$arguments] : ['git', '-C', $work, ...$arguments]);

        if (! $result->ok) {
            $verb = collect($arguments)->first(fn (string $argument) => ! str_starts_with($argument, '-') && ! str_contains($argument, '='));

            // A GitHub App that may only read (enough for deploys) cannot push the SDK.
            if ($verb === 'push' && preg_match('/Write access to repository not granted|returned error: 403|Permission to .* denied/i', $result->output) === 1) {
                throw new RuntimeException('GitHub did not let the panel push to '.$website->repository.': its GitHub App may only read. On GitHub, edit the app → Permissions → Repository → Contents: Read and write, accept the new permission where the app is installed, then click again. Or follow the steps below by hand and push yourself.');
            }

            throw new RuntimeException('git '.$verb.' failed: '.str_replace($pushUrl, (string) $website->repository, $result->output));
        }
    }

    private function must(CommandResult $result, string $what): void
    {
        if (! $result->ok) {
            throw new RuntimeException($what.' failed: '.mb_substr($result->output, -1500));
        }
    }

    private function read(SiteUser $site, string $path): ?string
    {
        if (! $site->separate()) {
            return is_file($path) ? (string) file_get_contents($path) : null;
        }

        $result = $this->files->exec(['cat', '--', $path], null, null, $site);

        return $result->ok ? $result->output."\n" : null;
    }

    private function exists(SiteUser $site, string $path): bool
    {
        if (! $site->separate()) {
            return file_exists($path);
        }

        return $this->files->exec(['test', '-e', $path], null, null, $site)->ok;
    }

    /** @return list<string> */
    private function list(string $work, string $folder): array
    {
        $result = $this->runner->runAsSite(['git', '-C', $work, 'ls-files', '--', $folder]);

        if (! $result->ok || $result->skipped) {
            return is_dir($work.'/'.$folder)
                ? array_map(fn ($file) => $folder.'/'.str_replace('\\', '/', $file->getRelativePathname()), File::allFiles($work.'/'.$folder))
                : [];
        }

        return array_values(array_filter(explode("\n", $result->output)));
    }
}
