<?php

namespace App\Services;

use App\Models\GithubApp;
use App\Models\PrivateKey;
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

    public function installationToken(GithubApp $app): string
    {
        $response = Http::withHeaders($this->headers($this->jwt($app)))
            ->acceptJson()
            ->post($this->endpoint($app, '/app/installations/'.$app->installation_id.'/access_tokens'));

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException($this->failure($response->status(), $response->json('message')));
        }

        return $token;
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
                ];
            }

            if (count($batch) < 100) {
                break;
            }
        }

        usort($repositories, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return $repositories;
    }

    private function jwt(GithubApp $app): string
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
