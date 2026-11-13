<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Models\Plugin;
use App\Services\PluginManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Link an installed plugin to a website. Its variables are written into the website\'s .env and its processes restart: PostgreSQL gives DB_*, DATABASE_URL and SPRING_DATASOURCE_*; Redis gives REDIS_URL and REDIS_HOST/PORT/PASSWORD; MinIO gives its S3 endpoint and keys; Vault gives VAULT_ADDR and VAULT_TOKEN. Only the variable names are returned, never the values. With unlink true, exactly those variables are removed again. PostgreSQL\'s DB_* replace the database settings the site had.')]
class LinkPlugin extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, PluginManager $manager): Response
    {
        $website = $this->findSite($request);
        $plugin = Plugin::query()->where('key', (string) $request->get('plugin'))->first()
            ?? throw ValidationException::withMessages(['plugin' => 'That plugin is not installed. Use list-plugins and install-plugin.']);

        if ($request->get('unlink', false)) {
            $manager->unlink($plugin, $website);

            return Response::json(['website' => $website->slug, 'plugin' => $plugin->key, 'linked' => false]);
        }

        return Response::json([
            'website' => $website->slug,
            'plugin' => $plugin->key,
            'linked' => true,
            'variables' => $manager->link($plugin, $website),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'plugin' => $schema->string()->description('The plugin key: postgres, redis, minio, or vault.')->required(),
            'unlink' => $schema->boolean()->description('Remove the link and its variables instead.'),
            'environment' => $this->environmentSchema($schema),
        ];
    }
}
