<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Models\Deployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The deployment history of a website, newest first: status, trigger, commit, and duration. Use get-deployment for one deployment\'s log.')]
class ListDeployments extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $limit = max(1, min(50, (int) $request->get('limit', 10)));

        return Response::json([
            'website' => $website->slug,
            'deployments' => $website->deployments()->latest('id')->limit($limit)->get()
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
            'limit' => $schema->integer()->description('How many (1 to 50, default 10).'),
        ];
    }
}
