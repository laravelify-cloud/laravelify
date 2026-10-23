<?php

namespace App\Services;

use App\Models\Website;
use App\Support\EnvFile;
use App\Support\ServerAddress;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Environments of a website: development, staging… Each is a website of its own (its folder,
 * port or domain, .env, and database) that deploys another branch of the same repository, and
 * is listed under the main website. The branch can be an existing one, or a new one made on
 * GitHub from any branch.
 */
class WebsiteEnvironments
{
    public function __construct(private WebsiteCreator $creator, private GithubAppClient $github) {}

    /**
     * @param  array{environment: string, branch: string, from?: ?string, domain?: ?string, http_port?: ?string}  $input
     *                                                                                                                    from: make the branch from this one first.
     * @return array{website: Website, error: ?string}
     *
     * @throws ValidationException
     */
    public function create(Website $main, array $input): array
    {
        $main = $main->parent ?? $main;
        $environment = strtolower(trim((string) $input['environment']));
        $branch = trim((string) $input['branch']);

        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,38}$/', $environment)) {
            throw ValidationException::withMessages(['environment' => 'Use a short name with letters, numbers and dashes, like development or staging.']);
        }

        if ($environment === 'production' || $main->environments()->where('environment', $environment)->exists()) {
            throw ValidationException::withMessages(['environment' => $main->name.' already has a '.$environment.' environment.']);
        }

        if ($branch === '' || ! preg_match('#^[A-Za-z0-9._/-]{1,120}$#', $branch) || str_contains($branch, '..')) {
            throw ValidationException::withMessages(['branch' => 'Choose a branch.']);
        }

        if (($from = trim((string) ($input['from'] ?? ''))) !== '') {
            $this->makeBranch($main, $branch, $from);
        }

        $result = $this->creator->create($main->group, [
            'name' => $main->name.' ('.$environment.')',
            'type' => $main->type,
            'domain' => (string) ($input['domain'] ?? ''),
            'http_port' => (string) ($input['http_port'] ?? ''),
            'repository' => (string) $main->repository,
            'branch' => $branch,
            'github_app_id' => (string) $main->github_app_id,
            'php_version' => (string) $main->php_version,
            'node_version' => (string) $main->node_version,
            'install_command' => (string) $main->install_command,
            'build_command' => (string) $main->build_command,
            'start_command' => (string) $main->start_command,
            'public_dir' => (string) $main->public_dir,
            'enable_reverb' => (bool) $main->enable_reverb,
            'provision_database' => (bool) $main->provision_database,
            'cloudflare_tunnel_id' => trim((string) ($input['domain'] ?? '')) !== '' ? (string) $main->cloudflare_tunnel_id : '',
        ]);

        $website = $result['website'];
        // The same settings to start from; the database lines are the environment's own (see Deployer).
        $website->update(['parent_id' => $main->id, 'environment' => $environment, 'env_content' => $this->environmentEnv($main, $website)]);

        if ($result['error'] === null) {
            $website->refresh()->queueDeployment('environment');
        }

        return ['website' => $website, 'error' => $result['error']];
    }

    /**
     * The main website's .env, made the environment's own: its address, its Redis key
     * prefixes, and (with a database made by the panel) its own database login.
     */
    private function environmentEnv(Website $main, Website $environment): string
    {
        $content = (string) $main->env_content;

        if ($content === '') {
            return $content;
        }

        $values = DatabaseProvisioner::redisValues($environment);

        if (preg_match('/^APP_URL=/m', $content)) {
            $values['APP_URL'] = $environment->domain ? 'https://'.$environment->domain : 'http://'.(ServerAddress::publicIp() ?? '127.0.0.1').':'.$environment->http_port;
        }

        if ($environment->provision_database && $environment->db_name) {
            $values += app(DatabaseProvisioner::class)->envValues($environment);
        }

        return EnvFile::set($content, $values);
    }

    /**
     * @throws ValidationException
     */
    private function makeBranch(Website $main, string $branch, string $from): void
    {
        $fullName = GithubAppClient::fullName((string) $main->repository);

        if ($fullName === null || ! $main->githubApp?->isConnected()) {
            throw ValidationException::withMessages(['branch' => 'New branches can be made only for GitHub repositories connected through a GitHub App. Push the branch yourself, then pick it.']);
        }

        try {
            $this->github->createBranch($main->githubApp, $fullName, $branch, $from);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['branch' => $exception->getMessage()]);
        }
    }
}
