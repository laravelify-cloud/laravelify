<?php

namespace App\Services;

use App\Models\Website;
use App\Support\Examples;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The git repository of a website made from a built-in example. It lives in the home folder of
 * the user that deploys the site: git refuses to clone a repository another user owns, and the
 * panel's storage stays closed to website users. The deploy makes it, as the site user, from the
 * example (or from the repository older versions kept in storage/app/examples, which it moves).
 */
class ExampleRepositories
{
    public function __construct(private SiteFiles $files) {}

    /**
     * The repository a new example website gets. The deploy moves it to the site's own home
     * once the site has its own user.
     */
    public function pathFor(string $slug): string
    {
        return app(SiteUser::class)->shared()->home().'/examples/'.$slug;
    }

    /**
     * Whether the website's repository is one of these, wherever it was made.
     */
    public function isExample(Website $website): bool
    {
        $slug = (string) $website->slug;
        $repository = rtrim(str_replace('\\', '/', (string) $website->repository), '/');

        if (! SiteUser::validSlug($slug) || $repository === '') {
            return false;
        }

        $candidates = [
            $this->legacyPath($slug),
            storage_path('app/process-home/examples/'.$slug),
            config('laravelify.site_home').'/examples/'.$slug,
            config('laravelify.site_users_root').'/'.$slug.'/home/examples/'.$slug,
        ];

        return in_array($repository, array_map(fn (string $path) => rtrim(str_replace('\\', '/', $path), '/'), $candidates), true);
    }

    /**
     * Make sure the example repository is in the home of the user that deploys the site, and
     * point the website at it. Returns a line for the deploy log when something was done.
     */
    public function ensure(Website $website, SiteUser $site): ?string
    {
        if (! $this->isExample($website)) {
            return null;
        }

        $target = $site->home().'/examples/'.$website->slug;
        $legacy = $this->legacyPath((string) $website->slug);
        // Where it was before: the panel's storage (older versions), or the shared user's home
        // (a website moved to its own user since), commits and all.
        $previous = rtrim((string) $website->repository, '/');
        $from = is_dir($legacy.'/.git') ? $legacy : ($previous !== $target && is_dir($previous.'/.git') ? $previous : null);

        if ($website->repository !== $target) {
            $website->update(['repository' => $target]);
        }

        if ($this->isRepository($target, $site)) {
            File::deleteDirectory($legacy);

            return null;
        }

        if ($from === null && ! Examples::has((string) $website->type)) {
            throw new RuntimeException('There is no example for '.$website->type.'. Set a Git repository instead.');
        }

        $this->copy($from ?? Examples::path((string) $website->type), $target, $site);

        if ($from === null) {
            $this->commit($target, $site);
        }

        File::deleteDirectory($legacy);

        return 'Made the example repository in '.$target.'.';
    }

    /**
     * Push the example to the website's new, empty GitHub repository, once. The push URL
     * carries a short-lived token: it is passed to git only and kept out of the log.
     */
    public function seed(Website $website, SiteUser $site, string $pushUrl): ?string
    {
        if (! $website->seed_example) {
            return null;
        }

        if (! Examples::has((string) $website->type)) {
            throw new RuntimeException('There is no example for '.$website->type.'. Push code to '.$website->repository.' instead.');
        }

        $target = $site->home().'/seeds/'.$website->slug;
        $this->copy(Examples::path((string) $website->type), $target, $site);
        $this->commit($target, $site);
        $result = $this->files->exec(['git', '-C', $target, 'push', '-q', '--', $pushUrl, 'HEAD:refs/heads/'.$website->branch], null, null, $site);
        $this->files->delete($target);

        if (! $result->ok) {
            throw new RuntimeException('Could not push the example to '.$website->repository.': '.str_replace($pushUrl, (string) $website->repository, $result->output));
        }

        $website->update(['seed_example' => false]);

        return 'Pushed the '.$website->type.' example to '.$website->repository.'.';
    }

    /**
     * Where older versions put example repositories, in the panel's storage (closed to site users now).
     */
    private function legacyPath(string $slug): string
    {
        return storage_path('app/examples/'.$slug);
    }

    private function isRepository(string $path, SiteUser $site): bool
    {
        if (! $site->separate()) {
            return is_dir($path.'/.git');
        }

        return $this->files->exec(['test', '-d', $path.'/.git'], null, null, $site)->ok;
    }

    /**
     * Copy a folder the panel can read into the site user's home: packed by the panel, unpacked
     * by the site user, so every file belongs to the site user.
     */
    private function copy(string $source, string $target, SiteUser $site): void
    {
        if (! $site->separate()) {
            File::deleteDirectory($target);
            File::ensureDirectoryExists($target);

            if (! File::copyDirectory($source, $target)) {
                throw new RuntimeException('Could not copy the example project.');
            }

            return;
        }

        $pack = new Process(['tar', '-C', $source, '-cf', '-', '.'], null, null, null, 300);
        $pack->run();

        if (! $pack->isSuccessful()) {
            throw new RuntimeException('Could not read the example project: '.trim($pack->getErrorOutput()));
        }

        $result = $this->files->exec(
            ['/bin/sh', '-c', 'rm -rf -- "$1" && mkdir -p -m 0700 -- "$1" && tar -xf - --no-same-owner -C "$1"', 'unpack', $target],
            $pack->getOutput(),
            null,
            $site,
        );

        if (! $result->ok) {
            throw new RuntimeException('Could not make the example repository: '.$result->output);
        }
    }

    private function commit(string $target, SiteUser $site): void
    {
        foreach ([
            ['init', '-q', '-b', 'main'],
            ['add', '-A'],
            ['-c', 'user.name=Laravelify', '-c', 'user.email=laravelify@localhost', 'commit', '-q', '-m', 'Example project'],
        ] as $arguments) {
            $result = $this->files->exec(['git', '-C', $target, ...$arguments], null, null, $site);

            if (! $result->ok) {
                throw new RuntimeException('Could not create the example repository: '.$result->output);
            }
        }
    }
}
