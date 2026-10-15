<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Stop a website\'s deployment that is queued or running (for example one that hangs). It stops at its next step.')]
class CancelDeployment extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $active = $website->deployments()->whereIn('status', ['queued', 'running'])->latest('id')->first()
            ?? throw ValidationException::withMessages(['website' => $website->name.' has no deployment running.']);

        $active->update([
            'status' => 'cancelled',
            'log' => trim($active->log."\nDeploy stopped."),
            'finished_at' => $active->status === 'queued' ? now() : $active->finished_at,
        ]);

        return Response::json(['cancelled' => $active->id, 'website' => $website->slug]);
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
