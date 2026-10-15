<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Deploy a website now: pull the branch, install, build, migrate, and restart. Returns a deployment id to follow with get-deployment.')]
class DeployWebsite extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $website = $this->findWebsite($request->get('website'));

        if (! $website->enabled) {
            throw ValidationException::withMessages(['website' => 'This website is turned off. Turn it on first (set-website-enabled).']);
        }

        if (! $website->repository) {
            throw ValidationException::withMessages(['website' => 'This website has no repository yet.']);
        }

        $deployment = $website->queueDeployment('mcp');

        if (! $deployment) {
            throw ValidationException::withMessages(['website' => 'A deployment is already running. Follow it with get-deployment.']);
        }

        return Response::json([
            'deployment_id' => $deployment->id,
            'status' => $deployment->status,
            'next' => 'Call get-deployment with deployment_id '.$deployment->id.' every 10–20 seconds until finished is true.',
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
