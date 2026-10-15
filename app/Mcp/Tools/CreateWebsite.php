<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Models\GithubApp;
use App\Models\Group;
use App\Services\GithubAppClient;
use App\Services\HostWriter;
use App\Services\WebsiteCreator;
use App\Support\EnvFile;
use App\Support\Slugs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Create a website from a Git repository and (by default) deploy it right away: nginx, SSL through the Cloudflare tunnel, the PHP or Node runtime, workers, and an optional MySQL database are set up for you. Give a domain (with a cloudflare_tunnel_id for HTTPS) or leave both empty for a free HTTP port. Sites from a connected GitHub App deploy again on every push.')]
class CreateWebsite extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, WebsiteCreator $creator, GithubAppClient $github, HostWriter $host): Response
    {
        // Checked first, so a wrong variable never leaves a half-made website behind.
        $env = $this->envValues((array) $request->get('env', []));
        $group = $this->group((string) $request->get('group', ''));
        $repository = $this->repositoryUrl((string) $request->get('repository', ''));
        $appId = (string) $request->get('github_app_id', '');

        if ($appId === '' && $repository !== '') {
            $appId = $this->appFor($repository, $github);
        }

        $result = $creator->create($group, [
            'name' => $request->get('name'),
            'type' => $request->get('type', 'laravel'),
            'domain' => $request->get('domain'),
            'http_port' => $request->get('http_port'),
            'repository' => $repository,
            'branch' => $request->get('branch', 'main'),
            'use_example' => $request->get('use_example', false),
            'php_version' => $request->get('php_version', '8.4'),
            'node_version' => $request->get('node_version', '22'),
            'install_command' => $request->get('install_command'),
            'build_command' => $request->get('build_command'),
            'start_command' => $request->get('start_command'),
            'public_dir' => $request->get('output_dir'),
            'enable_reverb' => $request->get('enable_reverb', false),
            'provision_database' => $request->get('create_database', false),
            'cloudflare_tunnel_id' => $request->get('cloudflare_tunnel_id'),
            'github_app_id' => $appId,
        ]);

        $website = $result['website'];
        $notes = [];

        if ($result['error'] !== null) {
            $notes[] = 'The website was saved, but applying nginx or the process manager failed: '.$result['error'];
        }

        if ($env !== []) {
            $website->update(['env_content' => EnvFile::set((string) $website->env_content, $env)]);

            try {
                $host->writeWebsite($website);
            } catch (Throwable $exception) {
                $notes[] = 'The environment was saved, but writing it on the server failed: '.$exception->getMessage();
            }
        }

        $deployment = null;

        if ($request->get('deploy', true) && $result['error'] === null && $website->repository) {
            $deployment = $website->fresh()->queueDeployment('mcp');
        }

        if (! $website->repository) {
            $notes[] = 'No repository yet: set one in the panel (General tab) before deploying.';
        }

        if ($website->repository && ! $website->github_app_id && ! $request->get('use_example', false)) {
            $notes[] = 'Not connected through a GitHub App, so pushes do not deploy by themselves. Add the repository webhook from the website\'s Deployments tab, or connect a GitHub App.';
        }

        return Response::json([
            'website' => $this->websiteSummary($website->fresh()),
            'deployment_id' => $deployment?->id,
            'next' => $deployment
                ? 'The deploy is queued. Call get-deployment with deployment_id '.$deployment->id.' every 10–20 seconds until finished is true.'
                : 'Call deploy-website when the website is ready.',
            'notes' => $notes,
        ]);
    }

    /**
     * The group by id or name; a new name creates the group.
     */
    private function group(string $reference): Group
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw ValidationException::withMessages(['group' => 'Give a group id or name (list-groups). A new name creates the group.']);
        }

        if (ctype_digit($reference)) {
            return Group::query()->find((int) $reference)
                ?? throw ValidationException::withMessages(['group' => 'No group with id '.$reference.'.']);
        }

        return Group::query()->where('name', $reference)->first()
            ?? Group::query()->create(['name' => $reference, 'slug' => Slugs::unique(Group::class, $reference)]);
    }

    /**
     * "owner/repo" becomes the GitHub HTTPS URL; full URLs stay as they are.
     */
    private function repositoryUrl(string $repository): string
    {
        $repository = trim($repository);

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository) === 1) {
            return 'https://github.com/'.preg_replace('/\.git$/', '', $repository).'.git';
        }

        return $repository;
    }

    /**
     * The connected GitHub App that can read this repository, when there is one.
     */
    private function appFor(string $repository, GithubAppClient $github): string
    {
        $wanted = strtolower((string) preg_replace('/\.git$/', '', $repository));

        foreach (GithubApp::query()->with('privateKey')->get()->filter->isConnected() as $app) {
            try {
                foreach ($github->repositories($app) as $candidate) {
                    if (strtolower((string) preg_replace('/\.git$/', '', $candidate['clone_url'])) === $wanted) {
                        return (string) $app->id;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return '';
    }

    /**
     * @param  array<int, mixed>  $lines  "KEY=value" strings
     * @return array<string, string>
     */
    private function envValues(array $lines): array
    {
        $values = [];

        foreach ($lines as $line) {
            [$key, $value] = array_pad(explode('=', (string) $line, 2), 2, null);
            $key = trim((string) $key);

            if ($value === null || ! EnvFile::validKey($key)) {
                throw ValidationException::withMessages(['env' => 'Write each variable as KEY=value ("'.$line.'" is not).']);
            }

            $values[$key] = $value;
        }

        try {
            EnvFile::set('', $values);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['env' => $exception->getMessage()]);
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->string()->description('Group id or name (list-groups). A new name creates the group.')->required(),
            'name' => $schema->string()->description('The website name, shown in the panel.')->required(),
            'type' => $schema->string()->description('The kind of app (list-site-types), like laravel, nextjs, react, express.')->required(),
            'repository' => $schema->string()->description('Git URL (https://github.com/owner/repo.git) or owner/repo. A connected GitHub App that can read it is used automatically.'),
            'branch' => $schema->string()->description('The branch to deploy (default main).'),
            'domain' => $schema->string()->description('Like app.example.com. Leave empty to use a free HTTP port instead.'),
            'cloudflare_tunnel_id' => $schema->integer()->description('Serve the domain over HTTPS through this Cloudflare tunnel (list-sources).'),
            'http_port' => $schema->integer()->description('A fixed HTTP port instead of a domain (default: the next free one from 8081).'),
            'github_app_id' => $schema->integer()->description('The GitHub App to clone with (list-sources). Found by itself when left out.'),
            'php_version' => $schema->string()->description('PHP sites: 8.0 to 8.5 (default 8.4).'),
            'node_version' => $schema->string()->description('Node and built front-ends: 20, 22, or 24 (default 22).'),
            'install_command' => $schema->string()->description('Override the install command (one line).'),
            'build_command' => $schema->string()->description('Override the build command (one line).'),
            'start_command' => $schema->string()->description('Node servers: override the start command (one line).'),
            'output_dir' => $schema->string()->description('Built front-ends and static sites: the folder to serve, like dist or out. Plain PHP: the web root (default public).'),
            'create_database' => $schema->boolean()->description('PHP sites: create a MySQL database and user and fill DB_* in .env.'),
            'enable_reverb' => $schema->boolean()->description('Laravel: run Reverb (websockets).'),
            'env' => $schema->array()->items($schema->string())->description('Extra environment variables as "KEY=value" strings, written before the first deploy.'),
            'deploy' => $schema->boolean()->description('Deploy right after creating it (default true).'),
            'use_example' => $schema->boolean()->description('Deploy Laravelify\'s example project for this type instead of a repository.'),
        ];
    }
}
