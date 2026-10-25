<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Support\EnvFile;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Error tracking with GlitchTip (MIT), an optional add-on on the panel's own server at /glitchtip.
 * The Sentry SDKs in a website (Laravel, PHP, Node, Next.js, browser apps) send its errors there:
 * each website gets a project (every website when GlitchTip is installed, and each new one), and
 * its key (DSN) goes into the website's .env under the name that site type's SDK reads, so the next
 * deploy picks it up.
 */
class Glitchtip
{
    public const VERSION = 'v6.2.6';

    public const ORGANIZATION = 'laravelify';

    public const TEAM = 'websites';

    /** Where the panel reaches GlitchTip's API (its own process; nginx is for browsers). */
    public const API = 'http://127.0.0.1:8095/glitchtip/api/0';

    public function __construct(private HostWriter $host) {}

    public function installed(): bool
    {
        return filled(Setting::getValue('glitchtip.token'));
    }

    /** GlitchTip in the browser, behind its own sign-in. */
    public function url(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').'/glitchtip/'.ltrim($path, '/');
    }

    /** A GlitchTip page, opened through the panel, which signs the browser in first. */
    public function open(string $path = ''): string
    {
        return route('tools.glitchtip', $path === '' ? [] : ['to' => $path]);
    }

    /** @return array{email: ?string, password: ?string} */
    public function login(): array
    {
        return ['email' => Setting::getValue('glitchtip.email'), 'password' => Setting::getValue('glitchtip.password')];
    }

    /**
     * Sign the panel's GlitchTip admin in, as a browser would (allauth's headless login), and return
     * GlitchTip's cookies: GlitchTip is on the panel's own address, so they can be handed to the
     * signed-in panel user's browser. Null when GlitchTip turned the sign-in down (its password
     * was changed there).
     *
     * @return array<string, string>|null name => value (sessionid, csrftoken)
     */
    public function signIn(): ?array
    {
        $login = $this->login();

        if (blank($login['email']) || blank($login['password'])) {
            return null;
        }

        $base = Http::baseUrl(str_replace('/api/0', '/_allauth/browser/v1', self::API))->acceptJson()->timeout(15);
        $csrf = (string) ($this->cookies($base->get('/auth/session'))['csrftoken'] ?? '');

        $response = $base->withHeaders(['Cookie' => 'csrftoken='.$csrf, 'X-CSRFToken' => $csrf, 'Referer' => $this->url()])
            ->post('/auth/login', ['email' => $login['email'], 'password' => $login['password']]);
        $cookies = $this->cookies($response);

        return $response->successful() && isset($cookies['sessionid']) ? $cookies + ['csrftoken' => $csrf] : null;
    }

    /**
     * The cookies a GlitchTip answer sets, from its Set-Cookie headers.
     *
     * @return array<string, string>
     */
    private function cookies(Response $response): array
    {
        $cookies = [];

        foreach ($response->headers()['Set-Cookie'] ?? $response->headers()['set-cookie'] ?? [] as $header) {
            [$pair] = explode(';', (string) $header, 2);
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $cookies[$name] = $value;
        }

        return $cookies;
    }

    /**
     * Install (or update) GlitchTip, keep its API token and admin, and make the organization and
     * team the websites' projects go in.
     */
    public function install(User $user): string
    {
        $result = $this->host->glitchtipInstall((string) config('app.url'), self::VERSION, (string) $user->email);
        Setting::putValue('glitchtip.token', $result['token']);
        Setting::putValue('glitchtip.email', $result['email'] ?? $user->email);

        if ($result['password'] !== null) {
            Setting::putValue('glitchtip.password', $result['password']);
        }

        $organizations = collect($this->api()->get('/organizations/')->throw()->json());

        if (! $organizations->contains('slug', self::ORGANIZATION)) {
            $this->api()->post('/organizations/', ['name' => 'Laravelify'])->throw();
        }

        $teams = collect($this->api()->get('/organizations/'.self::ORGANIZATION.'/teams/')->throw()->json());

        if (! $teams->contains('slug', self::TEAM)) {
            $this->api()->post('/organizations/'.self::ORGANIZATION.'/teams/', ['slug' => self::TEAM])->throw();
        }

        $result = $this->connectAll();

        return 'GlitchTip is ready at '.$this->url().'. '.$result['connected'].' '.str('website')->plural($result['connected']).' connected.'
            .($result['failed'] === [] ? '' : ' Could not connect: '.implode(', ', array_keys($result['failed'])).'.');
    }

    /**
     * Turn off error tracking: remove GlitchTip from the server (with every error it kept), take
     * each website's key out of its .env, and forget the projects and GlitchTip's login. The
     * Sentry SDK stays in the websites' code; without a key it sends nothing.
     */
    public function uninstall(): string
    {
        $this->host->glitchtipRemove();

        $websites = Website::query()->whereNotNull('glitchtip_project')->get();

        foreach ($websites as $website) {
            $keys = $this->envKeys($website);
            $website->forceFill([
                'glitchtip_project' => null,
                'glitchtip_project_id' => null,
                'env_content' => $keys === [] ? $website->env_content : EnvFile::set((string) $website->env_content, array_fill_keys($keys, null)),
            ])->save();
        }

        foreach (['glitchtip.token', 'glitchtip.email', 'glitchtip.password'] as $key) {
            Setting::putValue($key, null);
        }

        return 'Error tracking is off: GlitchTip and its errors are removed. '
            .($websites->isEmpty() ? '' : 'The key left the .env of '.$websites->count().' '.str('website')->plural($websites->count()).'; it stops being sent from their next deploy.');
    }

    /**
     * Connect every website that has no project yet, so each one shows in GlitchTip.
     *
     * @return array{connected: int, failed: array<string, string>} failed: website name => why
     */
    public function connectAll(): array
    {
        $connected = 0;
        $failed = [];

        foreach (Website::query()->whereNull('glitchtip_project')->orderBy('id')->get() as $website) {
            try {
                $this->connect($website);
                $connected++;
            } catch (Throwable $exception) {
                $failed[$website->name] = $exception->getMessage();
            }
        }

        return ['connected' => $connected, 'failed' => $failed];
    }

    /**
     * Give the website a project (once) and put its key in the website's .env.
     *
     * @return array{dsn: string, keys: list<string>}
     */
    public function connect(Website $website): array
    {
        if (! $this->installed()) {
            throw new RuntimeException('Turn on error tracking first.');
        }

        if ($website->glitchtip_project === null) {
            $project = $this->api()->post('/teams/'.self::ORGANIZATION.'/'.self::TEAM.'/projects/', [
                'name' => $website->name,
                'platform' => $this->platform($website),
            ])->throw()->json();
            $website->forceFill(['glitchtip_project' => $project['slug'], 'glitchtip_project_id' => (int) $project['id']])->save();
        }

        $dsn = $this->dsn($website);
        $keys = $this->envKeys($website);
        $website->update(['env_content' => EnvFile::set((string) $website->env_content, array_fill_keys($keys, $dsn))]);

        return ['dsn' => $dsn, 'keys' => $keys];
    }

    /** Delete the website's project (and its errors) from GlitchTip, when the website is deleted. */
    public function forget(Website $website): void
    {
        if ($website->glitchtip_project === null || ! $this->installed()) {
            return;
        }

        $response = $this->api()->delete('/projects/'.self::ORGANIZATION.'/'.$website->glitchtip_project.'/');

        // Already deleted in GlitchTip itself.
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    public function dsn(Website $website): string
    {
        $keys = $this->api()->get('/projects/'.self::ORGANIZATION.'/'.$website->glitchtip_project.'/keys/')->throw()->json();

        return (string) ($keys[0]['dsn']['public'] ?? throw new RuntimeException('That project has no key.'));
    }

    /**
     * The website's open issues, newest first.
     *
     * @return list<array{id: string, title: string, culprit: string, count: int, users: int, level: string, lastSeen: ?string, url: string}>
     */
    public function issues(Website $website, int $limit = 10): array
    {
        if ($website->glitchtip_project === null || ! $this->installed()) {
            return [];
        }

        $issues = $this->api()->get('/projects/'.self::ORGANIZATION.'/'.$website->glitchtip_project.'/issues/', ['query' => 'is:unresolved', 'limit' => $limit])->throw()->json();

        return array_map(fn (array $issue) => [
            'id' => (string) $issue['id'],
            'title' => (string) ($issue['title'] ?? $issue['metadata']['value'] ?? 'Error'),
            'culprit' => (string) ($issue['culprit'] ?? ''),
            'count' => (int) ($issue['count'] ?? 0),
            'users' => (int) ($issue['userCount'] ?? 0),
            'level' => (string) ($issue['level'] ?? 'error'),
            'lastSeen' => $issue['lastSeen'] ?? null,
            'url' => $this->open(self::ORGANIZATION.'/issues/'.$issue['id']),
        ], array_slice((array) $issues, 0, $limit));
    }

    /** The website's issues in GlitchTip. */
    public function projectUrl(Website $website): string
    {
        return $this->open(self::ORGANIZATION.'/issues?project='.$website->glitchtip_project_id);
    }

    /**
     * The .env names the website's SDK reads its key from: the server's, and the browser's when the
     * build puts it in the page (VITE_, NEXT_PUBLIC_, NUXT_PUBLIC_, PUBLIC_).
     *
     * @return list<string>
     */
    public function envKeys(Website $website): array
    {
        return match ($website->type) {
            'laravel' => ['SENTRY_LARAVEL_DSN', 'VITE_SENTRY_DSN'],
            'nextjs' => ['SENTRY_DSN', 'NEXT_PUBLIC_SENTRY_DSN'],
            'nuxt' => ['SENTRY_DSN', 'NUXT_PUBLIC_SENTRY_DSN'],
            'sveltekit' => ['SENTRY_DSN', 'PUBLIC_SENTRY_DSN'],
            'astro' => ['PUBLIC_SENTRY_DSN'],
            'react', 'vue', 'svelte' => ['VITE_SENTRY_DSN'],
            'angular', 'static' => [],
            default => ['SENTRY_DSN'],
        };
    }

    /**
     * Whether the website's code has a Sentry SDK: in composer.json (PHP), package.json (Node and
     * built front ends), or index.html (plain HTML, the CDN script). Null before the first deploy.
     */
    public function sdkFound(Website $website): ?bool
    {
        $root = rtrim((string) $website->deploy_path, '/');
        $folder = trim((string) $website->public_dir, '/');
        [$file, $needle] = match ($website->type) {
            'laravel', 'php' => ['composer.json', '"sentry/'],
            'static' => [($folder !== '' && $folder !== '.' ? $folder.'/' : '').'index.html', 'Sentry.init'],
            default => ['package.json', '"@sentry/'],
        };

        if (! is_file($root.'/'.$file) || ! is_readable($root.'/'.$file)) {
            return null;
        }

        return str_contains((string) file_get_contents($root.'/'.$file), $needle);
    }

    /** Which setup guide the website page shows. */
    public function guide(Website $website): string
    {
        return match ($website->type) {
            'laravel' => 'laravel',
            'php' => 'php',
            'nextjs' => 'nextjs',
            'nuxt' => 'nuxt',
            'sveltekit' => 'sveltekit',
            'react', 'vue', 'svelte', 'astro' => 'vite',
            'angular' => 'angular',
            'static' => 'browser',
            default => 'node',
        };
    }

    private function platform(Website $website): string
    {
        return match ($website->type) {
            'laravel' => 'php-laravel',
            'php' => 'php',
            'nextjs' => 'javascript-nextjs',
            'react' => 'javascript-react',
            'vue', 'nuxt' => 'javascript-vue',
            'angular' => 'javascript-angular',
            'svelte', 'sveltekit' => 'javascript-svelte',
            'astro', 'static' => 'javascript',
            default => 'node',
        };
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(self::API)->withToken((string) Setting::getValue('glitchtip.token'))->acceptJson()->asJson()->timeout(20);
    }
}
