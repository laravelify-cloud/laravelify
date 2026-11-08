<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Mcp\Concerns\ReadsEnvValues;
use App\Services\CloudflareRoutes;
use App\Services\WebsiteEnvironments;
use App\Support\DatabaseEngines;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Add an environment to a website, like development on the dev branch next to production. It is separate from production: its own folder, port, PM2 app or workers, nginx, .env, Cloudflare route and DNS, and (with create_database) its own database. Use this for production + development of one app: never create a second website. Deploys right away unless deploy is false.')]
class CreateSiteEnvironment extends Tool
{
    use DescribesWebsites;
    use ReadsEnvValues;

    public function handle(Request $request, WebsiteEnvironments $environments): Response
    {
        $main = $this->findWebsite($request->get('website'));
        $main = $main->parent ?? $main;
        $env = $this->envValues((array) $request->get('env', []));
        $source = filled($request->get('copy_env_from')) ? $this->findEnvironment($main, (string) $request->get('copy_env_from')) : $main;

        $input = [
            'environment' => (string) $request->get('name'),
            'branch' => (string) $request->get('branch'),
            'domain' => (string) $request->get('domain', ''),
            'aliases' => (array) $request->get('aliases', []),
            'include_www' => (bool) $request->get('include_www', false),
            'env' => $env,
            'env_source' => $source,
            'deploy' => (bool) $request->get('deploy', true),
        ];

        // Left out: taken from production.
        foreach (['cloudflare_tunnel_id' => 'cloudflare_tunnel_id', 'create_database' => 'provision_database', 'database_engine' => 'db_engine', 'enable_reverb' => 'enable_reverb'] as $parameter => $key) {
            if ($request->get($parameter) !== null) {
                $input[$key] = $request->get($parameter);
            }
        }

        $result = $environments->create($main, $input);
        $website = $result['website']->fresh();
        $notes = [];

        if ($result['error'] !== null) {
            $notes[] = 'The environment was saved, but applying nginx or the process manager failed: '.$result['error'];
        }

        if (filled($result['cloudflare']['note'] ?? null)) {
            $notes[] = $result['cloudflare']['note'];
        }

        return Response::json([
            'environment' => $this->environmentSummary($website),
            'cloudflare' => $result['cloudflare']['lines'] ?? [],
            'problems' => CloudflareRoutes::problems($result['cloudflare']),
            'deployment_id' => $result['deployment']?->id,
            'next' => $result['deployment']
                ? 'Call get-deployment with website "'.$main->slug.'", environment "'.$website->environment.'" (or deployment_id '.$result['deployment']->id.') every 10–20 seconds until finished is true.'
                : 'Call deploy-website with environment "'.$website->environment.'" when it is ready.',
            'notes' => $notes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The main website id, slug, or domain.')->required(),
            'name' => $schema->string()->description('The environment name, like development or staging (letters, numbers, dashes).')->required(),
            'branch' => $schema->string()->description('The branch it deploys, like dev. It must exist in the repository.')->required(),
            'domain' => $schema->string()->description('Its domain, like dev.example.com. Leave empty for a free HTTP port.'),
            'include_www' => $schema->boolean()->description('Also open on www.<domain>.'),
            'aliases' => $schema->array()->items($schema->string())->description('Other domains for this environment.'),
            'cloudflare_tunnel_id' => $schema->integer()->description('The tunnel for its domain (list-sources). Default: the one production uses.'),
            'create_database' => $schema->boolean()->description('Give it its own database, with DB_* in its .env. Default: like production.'),
            'database_engine' => $schema->string()->enum(DatabaseEngines::available())->description('With create_database: the engine. Default: like production.'),
            'env' => $schema->array()->items($schema->string())->description('"KEY=value" variables written on top of the copied .env.'),
            'copy_env_from' => $schema->string()->description('Copy the .env of this environment (id or name). Default: production. Its address, Redis prefix, and database lines become the new environment\'s own.'),
            'enable_reverb' => $schema->boolean()->description('Laravel: run Reverb. Default: like production.'),
            'deploy' => $schema->boolean()->description('Deploy right away (default true).'),
        ];
    }
}
