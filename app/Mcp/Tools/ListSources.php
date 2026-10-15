<?php

namespace App\Mcp\Tools;

use App\Models\CloudflareTunnel;
use App\Models\GithubApp;
use App\Services\GithubAppClient;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[Description('List the connected GitHub Apps (sources for private repositories; their sites deploy on every push), optionally with the repositories each App can read, and the Cloudflare tunnels (for HTTPS domains without open ports). Use the ids in create-website.')]
class ListSources extends Tool
{
    public function handle(Request $request, GithubAppClient $client): Response
    {
        $withRepositories = (bool) $request->get('include_repositories', false);

        $apps = GithubApp::query()->with('privateKey')->orderBy('name')->get()->map(function (GithubApp $app) use ($client, $withRepositories) {
            $entry = [
                'github_app_id' => $app->id,
                'name' => $app->name,
                'organization' => $app->organization,
                'connected' => $app->isConnected(),
            ];

            if ($withRepositories && $app->isConnected()) {
                try {
                    $entry['repositories'] = collect($client->repositories($app))
                        ->map(fn (array $repository) => ['name' => $repository['name'], 'clone_url' => $repository['clone_url'], 'default_branch' => $repository['branch']])
                        ->all();
                } catch (Throwable $exception) {
                    $entry['repositories_error'] = $exception->getMessage();
                }
            }

            return $entry;
        })->all();

        return Response::json([
            'github_apps' => $apps,
            'cloudflare_tunnels' => CloudflareTunnel::query()->orderBy('name')->get()->map(fn (CloudflareTunnel $tunnel) => [
                'cloudflare_tunnel_id' => $tunnel->id,
                'name' => $tunnel->name,
            ])->all(),
            'notes' => $apps === []
                ? 'No GitHub App yet: public repositories still work by URL. For private ones, connect a GitHub App in the panel under Sources.'
                : 'A repository must be one the App is installed on. If it is missing, install the App on it in GitHub (Settings → Applications → the App → Repository access).',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'include_repositories' => $schema->boolean()->description('Also list the repositories each connected App can read.'),
        ];
    }
}
