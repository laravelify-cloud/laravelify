<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Models\Deployment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show a deployment\'s status (queued, running, success, failed, cancelled) and the end of its log. Give a deployment id, or a website to see its latest deployment. Call it again every 10–20 seconds while it is queued or running.')]
class GetDeployment extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $lines = max(10, min(400, (int) $request->get('log_lines', 80)));
        $deployment = $request->get('deployment_id')
            ? Deployment::query()->find((int) $request->get('deployment_id'))
            : $this->findWebsite($request->get('website'))->deployments()->latest('id')->first();

        if (! $deployment) {
            throw ValidationException::withMessages(['website' => 'No such deployment.']);
        }

        $log = array_slice(preg_split('/\r\n|\n|\r/', rtrim((string) $deployment->log)) ?: [], -$lines);

        return Response::json($this->deploymentSummary($deployment) + [
            'website' => $deployment->website?->slug,
            'finished' => in_array($deployment->status, ['success', 'failed', 'cancelled'], true),
            'log_tail' => implode("\n", $log),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'deployment_id' => $schema->integer()->description('The deployment id (from deploy-website or create-website).'),
            'website' => $schema->string()->description('Or a website id, slug, or domain: its latest deployment.'),
            'log_lines' => $schema->integer()->description('How many log lines from the end (10 to 400, default 80).'),
        ];
    }
}
