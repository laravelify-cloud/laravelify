<?php

namespace App\Services;

use App\Models\Website;
use RuntimeException;

/**
 * VS Code's own clone of a website's repository, in the site user's home. It is kept apart from
 * the deployed folder, so a build never sees half-edited files: edits reach the site only through
 * a commit, a push, and the deploy that follows. Every command runs as the site user, and the
 * token-bearing remote URL is passed to git on the command line only.
 */
class ProjectWorkspace
{
    public function __construct(private SiteFiles $files, private SiteUser $sites) {}

    public function path(Website $website): string
    {
        // Sites not moved to their own user share one home, so each workspace is named after its site.
        return $this->user($website)->home().'/workspaces/'.$website->slug;
    }

    /**
     * Whether the site deploys from a repository VS Code can push to: a GitHub repository, or
     * the local repository of a built-in example (in the site user's home).
     */
    public function supports(Website $website): bool
    {
        if ($website->seed_example) {
            return false;
        }

        return str_starts_with((string) $website->repository, 'https://github.com/')
            || app(ExampleRepositories::class)->isExample($website);
    }

    /**
     * Clone the repository the first time, and bring it up to date (fast-forward only) after that.
     */
    public function prepare(Website $website): void
    {
        if (! $this->supports($website)) {
            throw new RuntimeException('The code editor needs a site that deploys from a GitHub repository or an example project.');
        }

        if (app(ExampleRepositories::class)->isExample($website)) {
            // In the home of the site's user (a site moved to its own user leaves it in the shared one).
            app(ExampleRepositories::class)->ensure($website, $this->user($website));
            $this->acceptPushes($website);
        }

        $path = $this->path($website);
        $remote = Deployer::repositoryWithToken($website);

        if (! $this->git($website, ['rev-parse', '--git-dir'])->ok) {
            $this->files->exec(['rm', '-rf', '--', $path], null, null, $this->user($website));
            $this->must($website, $this->files->exec(['git', 'clone', '-q', '--branch', (string) $website->branch, '--', $remote, $path], null, null, $this->user($website)), $remote);
            $this->must($website, $this->git($website, ['remote', 'set-url', 'origin', (string) $website->repository]), $remote);
        } else {
            // Local changes that were never committed stay; a branch that moved upstream is merged in only when it fast-forwards.
            $branch = trim($this->git($website, ['symbolic-ref', '--short', '-q', 'HEAD'])->output) ?: (string) $website->branch;
            $this->git($website, ['pull', '-q', '--ff-only', '--', $remote, $branch]);
        }

        $this->copyEnvironment($website);
    }

    /**
     * A .env is never in git, so the clone has none: it gets a copy of the deployed site's (once,
     * readable by the site's user only), for the Live Server and to read. git never commits it.
     * Changing the live site's settings stays the Environment tab's job.
     */
    private function copyEnvironment(Website $website): void
    {
        $path = $this->path($website);
        $user = $this->user($website);

        $this->files->exec(['/bin/sh', '-c', 'grep -qx ".env" "$1/.git/info/exclude" 2>/dev/null || { mkdir -p "$1/.git/info" && printf "\n.env\n" >> "$1/.git/info/exclude"; }', 'exclude', $path], null, null, $user);
        $this->files->exec(['/bin/sh', '-c', '[ -e "$1/.env" ] || [ ! -r "$2/.env" ] || { umask 077 && cp -- "$2/.env" "$1/.env"; }', 'env', $path, (string) $website->deploy_path], null, null, $user);
    }

    /**
     * The example's repository has its branch checked out: a push from VS Code updates its files
     * too instead of being refused, and then deploys the site, the way GitHub's webhook does for
     * a GitHub repository (a signed push event to the panel, over the server's own address).
     */
    private function acceptPushes(Website $website): void
    {
        $repository = (string) $website->repository;
        $user = $this->user($website);
        $this->files->exec(['git', '-C', $repository, 'config', 'receive.denyCurrentBranch', 'updateInstead'], null, null, $user);

        $hook = <<<'SH'
            #!/bin/sh
            # Written by Laravelify: a push to the website's branch deploys it, like GitHub's webhook.
            json() { sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | tr -d '\n\r\t'; }
            while read -r old new ref; do
              [ "$ref" = "refs/heads/__BRANCH__" ] || continue
              [ "$new" = "0000000000000000000000000000000000000000" ] && continue
              message=$(git log -1 --format=%s "$new" | head -c 300 | json)
              author=$(git log -1 --format=%an "$new" | json)
              body=$(printf '{"ref":"%s","after":"%s","head_commit":{"message":"%s","author":{"name":"%s"}}}' "$ref" "$new" "$message" "$author")
              signature=$(printf '%s' "$body" | openssl dgst -sha256 -hmac '__SECRET__' | sed 's/^.* //')
              curl -s -m 15 -o /dev/null -H 'Host: __HOST__' -H 'Content-Type: application/json' -H 'X-GitHub-Event: push' \
                -H "X-Hub-Signature-256: sha256=$signature" --data-binary "$body" 'http://127.0.0.1/hooks/github/__SLUG__' || true
            done
            SH;

        $hook = strtr($hook, [
            '__BRANCH__' => (string) $website->branch,
            '__SECRET__' => preg_replace('/[^A-Za-z0-9_]/', '', (string) $website->webhook_secret),
            '__HOST__' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            '__SLUG__' => (string) $website->slug,
        ]);

        $this->files->put($repository.'/.git/hooks/post-receive', $hook, 0755);
    }

    private function user(Website $website): SiteUser
    {
        return $this->sites->for($website);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(Website $website, array $arguments): CommandResult
    {
        return $this->files->exec(['git', '-C', $this->path($website), ...$arguments], null, null, $this->user($website));
    }

    private function must(Website $website, CommandResult $result, ?string $secretUrl = null): CommandResult
    {
        if (! $result->ok) {
            $output = $secretUrl !== null ? str_replace($secretUrl, (string) $website->repository, $result->output) : $result->output;

            throw new RuntimeException('git failed: '.preg_replace('#x-access-token:[^@\s]+@#', '', $output));
        }

        return $result;
    }
}
