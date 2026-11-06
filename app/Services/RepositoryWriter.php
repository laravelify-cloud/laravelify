<?php

namespace App\Services;

use App\Models\GithubApp;
use App\Models\Website;
use App\Support\GitInput;
use RuntimeException;
use Throwable;

/**
 * Changes a website's code the right way: in its Git repository, not in the deployed folder (a
 * deploy would undo that). The branch is cloned as the site's own user, the files are written,
 * committed and pushed, and a deploy is queued. Used by the AI chat's commit-files tool.
 */
class RepositoryWriter
{
    /** Most files and bytes one commit may write. */
    public const MAX_FILES = 20;

    public const MAX_BYTES = 1_000_000;

    public function __construct(
        private CommandRunner $runner,
        private SiteFiles $files,
    ) {}

    /**
     * @param  array<string, string>  $files  path in the repository => new contents
     * @return array{commit: string, files: list<string>, deploying: bool}
     */
    public function commit(Website $website, array $files, string $message): array
    {
        if (blank($website->repository)) {
            throw new RuntimeException($website->name.' has no Git repository to commit to.');
        }

        GitInput::assertSafe((string) $website->repository, $website->branch);
        $files = $this->checked($files);
        $message = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $message) ?? '') ?: 'Fix issues (Laravelify AI)';

        $site = app(SiteUser::class)->for($website);
        $this->runner->actAs($site);
        $work = $site->home().'/ai-commits/'.$website->slug;
        $pushUrl = $this->pushUrl($website);

        try {
            if ($this->exists($site, $work)) {
                $this->files->delete($work);
            }

            $this->files->makeDirectory(dirname($work), 0700, true);
            $this->git(['clone', '-q', '--depth', '1', '--branch', $website->branch, '--single-branch', '--', $pushUrl, $work], null, $pushUrl, $website);

            foreach ($files as $path => $contents) {
                $this->files->makeDirectory(dirname($work.'/'.$path), 0755, true);
                $this->files->put($work.'/'.$path, $contents);
            }

            $this->git(['add', '-A', '--', ...array_keys($files)], $work, $pushUrl, $website);

            if ($this->runner->runAsSite(['git', '-C', $work, 'diff', '--cached', '--quiet'])->ok) {
                throw new RuntimeException('Nothing changed: the files already have this content.');
            }

            $this->git(['-c', 'user.name=Laravelify AI', '-c', 'user.email=laravelify@localhost', 'commit', '-q', '-m', $message], $work, $pushUrl, $website);
            $sha = trim($this->runner->runAsSite(['git', '-C', $work, 'rev-parse', '--short', 'HEAD'])->output);

            // An example's repository has its branch checked out: let the push update it.
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
                // A leftover clone is removed by the next commit.
            }

            $this->runner->actAs(null);
        }

        $website->refresh();
        $deploying = $website->queueDeployment('ai') !== null || $website->hasActiveDeployment();

        return ['commit' => $sha, 'files' => array_keys($files), 'deploying' => $deploying];
    }

    /**
     * Only plain, relative paths inside the repository, never Git's own files, and not too much.
     *
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function checked(array $files): array
    {
        if ($files === []) {
            throw new RuntimeException('No files to commit.');
        }

        if (count($files) > self::MAX_FILES) {
            throw new RuntimeException('Up to '.self::MAX_FILES.' files per commit.');
        }

        $clean = [];
        $bytes = 0;

        foreach ($files as $path => $contents) {
            $path = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
            $parts = explode('/', $path);

            if ($path === '' || in_array('..', $parts, true) || in_array('.', $parts, true) || $parts[0] === '.git' || str_contains($path, "\0")
                || preg_match('#^[A-Za-z0-9._@+ /-]+$#', $path) !== 1) {
                throw new RuntimeException('Not a file path in the repository: '.$path);
            }

            $bytes += strlen((string) $contents);
            $clean[$path] = (string) $contents;
        }

        if ($bytes > self::MAX_BYTES) {
            throw new RuntimeException('Too much at once: up to 1 MB per commit.');
        }

        return $clean;
    }

    /**
     * The URL to clone and push with: a connected GitHub App that may push to the repository, or
     * the website's own token.
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
                    return 'https://x-access-token:'.rawurlencode($github->installationToken($app, $fullName)).'@github.com/'.$fullName.'.git';
                }
            } catch (Throwable) {
                // Not installed on this repository, or GitHub did not answer: try the next one.
            }
        }

        return Deployer::repositoryWithToken($website);
    }

    /**
     * Git as the site user. The push URL may carry a token: it never shows in a message.
     *
     * @param  list<string>  $arguments
     */
    private function git(array $arguments, ?string $work, string $pushUrl, Website $website): void
    {
        $result = $this->runner->runAsSite($work === null ? ['git', ...$arguments] : ['git', '-C', $work, ...$arguments]);

        if (! $result->ok) {
            $verb = collect($arguments)->first(fn (string $argument) => ! str_starts_with($argument, '-') && ! str_contains($argument, '='));

            if ($verb === 'push' && preg_match('/Write access to repository not granted|returned error: 403|Permission to .* denied/i', $result->output) === 1) {
                throw new RuntimeException('GitHub did not let the panel push to '.$website->repository.': its GitHub App may only read. Give it Contents: Read and write on GitHub, then try again.');
            }

            throw new RuntimeException('git '.$verb.' failed: '.str_replace($pushUrl, (string) $website->repository, $result->output));
        }
    }

    private function exists(SiteUser $site, string $path): bool
    {
        return $site->separate() ? $this->files->exec(['test', '-e', $path], null, null, $site)->ok : file_exists($path);
    }
}
