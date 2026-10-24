<?php

namespace App\Services;

use App\Models\CodeWorkspace;
use App\Models\PanelTask;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * VS Code workspaces: projects opened together. A workspace of one project is that website's own
 * editor (opening a website's VS Code adds it here). One of several projects gets an editor of its
 * own, as the workspace's own Unix user, which reaches exactly those projects' folders (ACLs set by
 * host-apply.sh workspace-start) and nothing else of the websites. A share link, while turned on,
 * lets anyone who has it open the workspace's editor.
 */
class CodeWorkspaces
{
    public function __construct(
        private HostWriter $host,
        private CodeServer $editors,
        private ProjectWorkspace $projects,
        private PortAllocator $ports,
        private SiteFiles $files,
        private SiteUser $sites,
        private GithubAppClient $github,
    ) {}

    /** The one-project workspace of a website, made the first time its editor opens. */
    public function forWebsite(Website $website, ?User $user = null): CodeWorkspace
    {
        $existing = CodeWorkspace::query()
            ->whereHas('websites', fn ($query) => $query->whereKey($website->id))
            ->has('websites', '=', 1)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($website, $user) {
            $workspace = CodeWorkspace::query()->create([
                'key' => $this->newKey(),
                'name' => $website->name,
                'user_id' => $user?->id,
            ]);
            $workspace->websites()->attach($website->id);

            return $workspace;
        });
    }

    /**
     * A workspace of the picked projects, named after them unless a name is given. One project
     * opens its website's own editor; a website has one such workspace, so picking it again only
     * renames that one.
     *
     * @param  list<int>  $websiteIds
     */
    public function create(array $websiteIds, ?User $user = null, ?string $name = null): CodeWorkspace
    {
        $websites = Website::query()->whereKey($websiteIds)->orderBy('name')->get();

        if ($websites->isEmpty()) {
            throw new RuntimeException('Pick a project for the workspace.');
        }

        // A website still on the shared user is fine: its move to its own user starts with the
        // workspace (see moveToOwnUsers). Any other reason stops here.
        foreach ($websites as $website) {
            if (($reason = $this->editors->unavailableReason($website)) !== null && ! $this->editors->canMoveToOwnUser($website)) {
                throw new RuntimeException($website->name.': '.$reason);
            }
        }

        if ($websites->count() === 1) {
            $workspace = $this->forWebsite($websites->first(), $user);

            if (trim((string) $name) !== '') {
                $workspace->update(['name' => Str::limit(trim((string) $name), 80, '')]);
            }

            return $workspace;
        }

        return DB::transaction(function () use ($websites, $user, $name) {
            $workspace = CodeWorkspace::query()->create([
                'key' => $this->newKey(),
                'name' => trim((string) $name) !== '' ? Str::limit(trim((string) $name), 80, '') : $this->nameFor($websites),
                'user_id' => $user?->id,
            ]);
            $workspace->websites()->attach($websites->modelKeys());

            return $workspace;
        });
    }

    /**
     * "Shop + Blog", "Shop + Blog + Api", or "Shop + Blog + 2 more"; a number when it is taken.
     *
     * @param  Collection<int, Website>  $websites
     */
    public function nameFor(Collection $websites): string
    {
        $names = $websites->pluck('name')->map(fn ($name) => trim((string) $name))->values();
        $base = $names->count() > 3
            ? $names->take(2)->implode(' + ').' + '.($names->count() - 2).' more'
            : $names->implode(' + ');
        $name = $base;

        for ($number = 2; CodeWorkspace::query()->where('name', $name)->exists(); $number++) {
            $name = $base.' '.$number;
        }

        return $name;
    }

    /** A website VS Code can open once it is moved to its own user. */
    public function needsMove(Website $website): bool
    {
        return $this->editors->unavailableReason($website) !== null && $this->editors->canMoveToOwnUser($website);
    }

    /**
     * Move the workspace's websites that still run as the shared user to their own users, in the
     * background (one task each, as the VS Code launcher does); a move already running is followed.
     *
     * @return list<int> The ids of the move tasks still to finish.
     */
    public function moveToOwnUsers(CodeWorkspace $workspace): array
    {
        $tasks = app(PanelTaskRunner::class);
        $ids = [];

        foreach ($workspace->websites as $website) {
            if (! $this->needsMove($website)) {
                continue;
            }

            $scope = 'websites:own-user:'.$website->id;
            $running = PanelTask::query()->where('scope', $scope)->whereIn('status', [PanelTask::QUEUED, PanelTask::RUNNING])->latest('id')->first();
            $ids[] = ($running ?? $tasks->start('websites.own-user', $scope, 'Moving '.$website->name.' to its own user', ['website_id' => $website->id]))->id;
        }

        return $ids;
    }

    public function url(CodeWorkspace $workspace): ?string
    {
        if (! $workspace->isMulti()) {
            $website = $workspace->websites->first();

            return $website ? $this->editors->url($website) : null;
        }

        return $workspace->code_server_token
            ? rtrim((string) config('app.url'), '/').'/code/'.$workspace->code_server_token.'/?workspace='.rawurlencode($this->user($workspace)->home().'/workspace.code-workspace')
            : null;
    }

    /**
     * Start the workspace's editor when needed.
     *
     * @return array{url: ?string, installing: ?int, total?: int} As CodeServer::start.
     */
    public function start(CodeWorkspace $workspace, string $author): array
    {
        $workspace->loadMissing('websites');

        if ($workspace->websites->isEmpty()) {
            throw new RuntimeException('This workspace has no projects left: its websites were deleted.');
        }

        $workspace->forceFill(['opened_at' => now()])->save();

        if (! $workspace->isMulti()) {
            return $this->editors->start($workspace->websites->first(), $author);
        }

        if ($workspace->code_server_token && $workspace->code_server_port && $this->status($workspace)['running']) {
            $this->writeGitCredentials($workspace, $author);
            $this->editors->shareAgentSignIns();

            return ['url' => (string) $this->url($workspace), 'installing' => null];
        }

        $install = $this->host->codeServerInstall();

        if ($install !== 'installed' && ! str_starts_with($install, '[skipped]')) {
            $parts = explode(' ', $install);

            return ['url' => null, 'installing' => (int) ($parts[1] ?? 0), 'total' => (int) ($parts[2] ?? 0)];
        }

        foreach ($workspace->websites as $website) {
            if (($reason = $this->editors->unavailableReason($website)) !== null) {
                throw new RuntimeException($website->name.': '.$reason);
            }

            $this->projects->prepare($website);
        }

        $workspace->forceFill([
            'code_server_port' => $workspace->code_server_port ?: $this->ports->random(13000, 13999),
            'code_server_token' => $workspace->code_server_token ?: Str::lower(Str::random(32)),
        ])->save();

        // The user and its home come first (host-apply.sh makes them); then its git and editor setup.
        $this->host->workspaceStart($workspace->key, (int) $workspace->code_server_port, (string) $workspace->code_server_token, $this->slugs($workspace));
        $this->writeGitCredentials($workspace, $author);
        $this->editors->shareAgentSignIns();
        $this->editors->prepareProfileFor($this->user($workspace));
        $this->editors->waitForPort((int) $workspace->code_server_port);

        return ['url' => (string) $this->url($workspace), 'installing' => null];
    }

    /**
     * @return array{running: bool, idle_seconds: ?int}
     */
    public function status(CodeWorkspace $workspace): array
    {
        if (! $workspace->isMulti()) {
            $website = $workspace->websites->first();

            return $website ? $this->editors->status($website) : ['running' => false, 'idle_seconds' => null];
        }

        $line = trim($this->host->workspaceStatus($workspace->key));
        [$state, $age] = array_pad(explode(' ', $line), 2, '-1');

        return ['running' => $state === 'active', 'idle_seconds' => (int) $age >= 0 ? (int) $age : null];
    }

    public function stop(CodeWorkspace $workspace): void
    {
        if ($workspace->isMulti()) {
            $this->host->workspaceStop($workspace->key);
        } elseif ($website = $workspace->websites->first()) {
            $this->editors->stop($website);
        }
    }

    /**
     * Delete a workspace. Its projects stay as they are; a workspace of several also loses its
     * editor, its user, and that user's access to them.
     */
    public function delete(CodeWorkspace $workspace): void
    {
        $workspace->loadMissing('websites');

        if ($workspace->isMulti() || $workspace->code_server_token) {
            $this->host->workspaceDrop($workspace->key, $this->slugs($workspace));
        }

        $workspace->delete();
    }

    /** A new share link (the old one stops working). */
    public function share(CodeWorkspace $workspace): string
    {
        $token = Str::lower(Str::random(40));
        $workspace->forceFill(['share_token' => $token])->save();

        return $token;
    }

    public function unshare(CodeWorkspace $workspace): void
    {
        $workspace->forceFill(['share_token' => null])->save();
    }

    public function shareUrl(CodeWorkspace $workspace): ?string
    {
        return $workspace->share_token ? route('workspaces.shared', $workspace->share_token) : null;
    }

    /**
     * The workspace a share link (kept in the visitor's cookie) opens, when it opens the editor at
     * this token: the workspace's own, or its single website's.
     */
    public function sharedEditor(?string $shareToken, string $editorToken): ?CodeWorkspace
    {
        if ($shareToken === null || preg_match('/^[a-z0-9]{40}$/', $shareToken) !== 1) {
            return null;
        }

        $workspace = CodeWorkspace::query()->with('websites')->where('share_token', $shareToken)->first();

        if ($workspace === null) {
            return null;
        }

        $tokens = $workspace->isMulti()
            ? [$workspace->code_server_token]
            : [$workspace->websites->first()?->code_server_token];

        return in_array($editorToken, array_filter($tokens), true) ? $workspace : null;
    }

    /**
     * The projects' code in one zip (no installed packages, builds, or .env files); returns its path.
     */
    public function zip(CodeWorkspace $workspace): string
    {
        $workspace->loadMissing('websites');

        foreach ($workspace->websites as $website) {
            if ($this->editors->unavailableReason($website) === null) {
                $this->projects->prepare($website);
            }
        }

        $path = trim((string) collect(explode("\n", trim($this->host->workspaceZip(Str::lower(Str::random(24)), $this->slugs($workspace)))))->last());

        if (! is_file($path)) {
            throw new RuntimeException('Could not make the zip.');
        }

        return $path;
    }

    /**
     * git in the workspace's editor asks a credential helper per repository (useHttpPath): each
     * website's GitHub App token, in a file only the workspace's user reads. Called at every start
     * and every 30 minutes (installation tokens last an hour).
     */
    public function writeGitCredentials(CodeWorkspace $workspace, ?string $author): void
    {
        $user = $this->user($workspace);
        $dir = $user->home().'/.laravelify/git-tokens';
        $this->files->exec(['mkdir', '-p', '-m', '0700', $dir], null, null, $user);

        foreach ($workspace->websites as $website) {
            $app = $website->githubApp;
            $repository = GithubAppClient::fullName((string) $website->repository);

            if (! $app?->isConnected() || $repository === null) {
                continue;
            }

            $token = $this->github->installationToken($app, $repository);
            $this->files->exec(['/bin/sh', '-c', 'umask 077 && cat > "$1"', 'token', $dir.'/'.str_replace('/', '_', strtolower($repository))], (string) $token, null, $user);
        }

        // git sends path=owner/repo(.git); the token file is owner_repo.
        $helper = '!f() { test "$1" = get || exit 0; p=$(sed -n "s/^path=//p" | sed "s/\\.git$//" | tr "A-Z/" "a-z_"); echo username=x-access-token; echo "password=$(cat "$HOME/.laravelify/git-tokens/$p" 2>/dev/null)"; }; f';
        $config = $user->home().'/.gitconfig';
        $commands = [
            ['git', 'config', '--file', $config, 'credential.https://github.com.helper', $helper],
            ['git', 'config', '--file', $config, 'credential.https://github.com.useHttpPath', 'true'],
        ];

        if ($author !== null && trim($author) !== '') {
            $commands[] = ['git', 'config', '--file', $config, 'user.name', trim($author)];
            $commands[] = ['git', 'config', '--file', $config, 'user.email', 'editor@laravelify.local'];
        }

        foreach ($commands as $command) {
            $this->files->exec($command, null, null, $user);
        }
    }

    /** A fresh git token for a running workspace editor (every 30 minutes). */
    public function refreshCredentials(CodeWorkspace $workspace): void
    {
        if ($workspace->isMulti() && $this->status($workspace)['running']) {
            $this->writeGitCredentials($workspace, null);
        }
    }

    public function user(CodeWorkspace $workspace): SiteUser
    {
        return $this->sites->forWorkspace($workspace->key);
    }

    /**
     * @return list<string>
     */
    private function slugs(CodeWorkspace $workspace): array
    {
        return $workspace->websites->pluck('slug')->map(fn ($slug) => (string) $slug)->values()->all();
    }

    private function newKey(): string
    {
        do {
            $key = Str::lower(Str::random(10));
        } while (! preg_match('/^[a-z0-9]{10}$/', $key) || CodeWorkspace::query()->where('key', $key)->exists());

        return $key;
    }
}
