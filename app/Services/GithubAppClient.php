<?php

namespace App\Services;

use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GithubAppClient
{
    public function testConnection(GithubApp $app): string
    {
        $response = Http::withHeaders($this->headers($this->jwt($app)))
            ->acceptJson()
            ->get($this->endpoint($app, '/app/installations/'.$app->installation_id));

        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->status(), $response->json('message')));
        }

        $login = $response->json('account.login');

        return $login ? 'Connected to GitHub as '.$login.'.' : 'GitHub accepted this app.';
    }

    /**
     * A token of the installation (it lasts an hour). With $repository ("name" or "owner/name")
     * the token can reach only that repository, as the code editor's git gets.
     */
    public function installationToken(GithubApp $app, ?string $repository = null): string
    {
        $request = Http::withHeaders($this->headers($this->jwt($app)))->acceptJson();
        $url = $this->endpoint($app, '/app/installations/'.$app->installation_id.'/access_tokens');
        $response = $repository === null
            ? $request->post($url)
            : $request->post($url, ['repositories' => [str_contains($repository, '/') ? substr($repository, strrpos($repository, '/') + 1) : $repository]]);

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException($this->failure($response->status(), $response->json('message')));
        }

        return $token;
    }

    /**
     * The account the app is installed on, and what the installation may do.
     *
     * @return array{login: string, type: string, permissions: array<string, string>}
     */
    public function installation(GithubApp $app): array
    {
        $response = Http::withHeaders($this->headers($this->jwt($app)))
            ->acceptJson()
            ->get($this->endpoint($app, '/app/installations/'.$app->installation_id));

        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->status(), $response->json('message')));
        }

        return [
            'login' => (string) $response->json('account.login'),
            'type' => (string) $response->json('account.type'),
            'permissions' => array_map('strval', (array) $response->json('permissions', [])),
        ];
    }

    /**
     * Whether the installation may create repositories and push to them.
     */
    public function canCreateRepositories(GithubApp $app): bool
    {
        $installation = $this->installation($app);

        return $installation['type'] === 'Organization'
            && ($installation['permissions']['administration'] ?? null) === 'write'
            && ($installation['permissions']['contents'] ?? null) === 'write';
    }

    /**
     * Make an empty repository in the organization the app is installed on. GitHub Apps
     * cannot make repositories in a personal account.
     *
     * @return array{name: string, clone_url: string, branch: string}
     */
    public function createRepository(GithubApp $app, string $name, bool $private = true): array
    {
        $installation = $this->installation($app);

        if ($installation['type'] !== 'Organization') {
            throw new RuntimeException('This GitHub App is installed on a personal account. Install it on an organization to create repositories.');
        }

        $response = Http::withToken($this->installationToken($app))
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->post($this->endpoint($app, '/orgs/'.rawurlencode($installation['login']).'/repos'), [
                'name' => $name,
                'private' => $private,
                'auto_init' => false,
            ]);

        if (! $response->successful()) {
            $message = $response->status() === 403
                ? 'the app may not create repositories. Give it Administration and Contents write access on GitHub, then accept the new permissions'
                : $response->json('errors.0.message') ?? $response->json('message');

            throw new RuntimeException($this->failure($response->status(), $message));
        }

        return [
            'name' => (string) $response->json('full_name'),
            'clone_url' => (string) $response->json('clone_url'),
            'branch' => 'main',
        ];
    }

    /**
     * The branches of a repository ("owner/name").
     *
     * @return list<string>
     */
    public function branches(GithubApp $app, string $fullName): array
    {
        $response = $this->repositoryRequest($app)->get($this->endpoint($app, '/repos/'.$fullName.'/branches'), ['per_page' => 100]);

        if (! $response->successful()) {
            throw new RuntimeException($this->failure($response->status(), $response->json('message')));
        }

        return array_values(array_map(fn (array $branch): string => (string) $branch['name'], array_filter((array) $response->json(), fn ($branch) => is_array($branch) && isset($branch['name']))));
    }

    /**
     * Make a new branch on GitHub from the tip of another one.
     */
    public function createBranch(GithubApp $app, string $fullName, string $name, string $from): void
    {
        $base = $this->repositoryRequest($app)->get($this->endpoint($app, '/repos/'.$fullName.'/git/ref/heads/'.$from));
        $sha = $base->json('object.sha');

        if (! $base->successful() || ! is_string($sha)) {
            throw new RuntimeException('The branch '.$from.' was not found on GitHub.');
        }

        $response = $this->repositoryRequest($app)->post($this->endpoint($app, '/repos/'.$fullName.'/git/refs'), ['ref' => 'refs/heads/'.$name, 'sha' => $sha]);

        if (! $response->successful()) {
            $message = $response->status() === 422 ? 'a branch named '.$name.' already exists' : $response->json('message');

            throw new RuntimeException($this->failure($response->status(), $message === null ? null : (string) $message));
        }
    }

    private function repositoryRequest(GithubApp $app): PendingRequest
    {
        return Http::withToken($this->installationToken($app))
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15);
    }

    /**
     * "owner/name" from a GitHub clone URL.
     */
    public static function fullName(string $repository): ?string
    {
        return preg_match('#github\.com[/:]([^/]+/[^/]+?)(?:\.git)?/?$#', trim($repository), $match) ? $match[1] : null;
    }

    /**
     * The text of files at the top of a repository (null for those that are missing), read
     * through the contents API without cloning.
     *
     * @param  list<string>  $paths
     * @return array<string, ?string>
     */
    public function files(GithubApp $app, string $fullName, string $branch, array $paths): array
    {
        $token = $this->installationToken($app);
        $files = [];

        foreach ($paths as $path) {
            $response = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github.raw+json', 'X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(10)
                ->get($this->endpoint($app, '/repos/'.$fullName.'/contents/'.$path), ['ref' => $branch]);

            $files[$path] = $response->successful() ? $response->body() : null;
        }

        return $files;
    }

    /**
     * @return list<array{name: string, clone_url: string, branch: string}>
     */
    public function repositories(GithubApp $app): array
    {
        $token = $this->installationToken($app);
        $repositories = [];

        for ($page = 1; $page <= 5; $page++) {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->get($this->endpoint($app, '/installation/repositories'), [
                    'per_page' => 100,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException($this->failure($response->status(), $response->json('message')));
            }

            $batch = $response->json('repositories');

            if (! is_array($batch) || $batch === []) {
                break;
            }

            foreach ($batch as $repository) {
                if (! is_array($repository) || ! isset($repository['full_name'], $repository['clone_url'])) {
                    continue;
                }

                $repositories[] = [
                    'name' => (string) $repository['full_name'],
                    'clone_url' => (string) $repository['clone_url'],
                    'branch' => (string) ($repository['default_branch'] ?? 'main'),
                    'private' => (bool) ($repository['private'] ?? false),
                    'language' => (string) ($repository['language'] ?? ''),
                    'description' => mb_substr((string) ($repository['description'] ?? ''), 0, 140),
                    'pushed_at' => (string) ($repository['pushed_at'] ?? ''),
                ];
            }

            if (count($batch) < 100) {
                break;
            }
        }

        usort($repositories, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $repositories;
    }

    protected function jwt(GithubApp $app): string
    {
        $pem = PrivateKey::normalize((string) $app->signingKey());
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new RuntimeException('The private key is not a valid PEM.');
        }

        $header = $this->base64(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $this->base64([
            'iat' => time() - 60,
            'exp' => time() + (9 * 60),
            'iss' => (string) $app->app_id,
        ]);
        $data = $header.'.'.$payload;
        openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return $data.'.'.$this->base64($signature, raw: true);
    }

    /**
     * @param  array<string, mixed>|string  $value
     */
    private function base64(array|string $value, bool $raw = false): string
    {
        $json = $raw ? $value : json_encode($value);

        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $jwt): array
    {
        return [
            'Authorization' => 'Bearer '.$jwt,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ];
    }

    private function endpoint(GithubApp $app, string $path): string
    {
        return rtrim($app->api_url ?: 'https://api.github.com', '/').$path;
    }

    private function failure(int $status, mixed $message): string
    {
        $detail = is_string($message) && $message !== '' ? $message : 'request failed';

        return 'GitHub returned '.$status.': '.$detail;
    }
}
