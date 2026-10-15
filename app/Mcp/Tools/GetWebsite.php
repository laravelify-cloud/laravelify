<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Models\Deployment;
use App\Support\EnvFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show one website: settings, runtime, the names of its environment variables (values are never shown), and its recent deployments.')]
class GetWebsite extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $website = $this->findWebsite($request->get('website'));

        return Response::json($this->websiteSummary($website) + [
            'php_version' => $website->php_version,
            'node_version' => $website->node_version,
            'install_command' => $website->install_command,
            'build_command' => $website->build_command,
            'start_command' => $website->start_command,
            'output_dir' => $website->public_dir,
            'database' => $website->provision_database ? $website->db_name : null,
            'env_keys' => EnvFile::keys((string) $website->env_content),
            'recent_deployments' => $website->deployments()->latest('id')->limit(5)->get()
                ->map(fn (Deployment $deployment) => $this->deploymentSummary($deployment))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
        ];
    }
}
