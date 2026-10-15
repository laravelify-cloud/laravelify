<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\WebsiteSwitch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Turn a website off (its address shows a "turned off" page, workers stop, deploys and alerts pause; nothing is deleted) or on again.')]
class SetWebsiteEnabled extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, WebsiteSwitch $switch): Response
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $website = $this->findWebsite($request->get('website'));
        $enabled = (bool) $request->get('enabled');

        if ($website->enabled !== $enabled) {
            $enabled ? $switch->enable($website) : $switch->disable($website);
        }

        return Response::json(['website' => $website->slug, 'enabled' => (bool) $website->fresh()->enabled]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'enabled' => $schema->boolean()->description('true to turn it on, false to turn it off.')->required(),
        ];
    }
}
