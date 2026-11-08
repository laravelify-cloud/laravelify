<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the environments of a website: production (the main website) and any others like development or staging, each with its branch, domains, status, last deployment, and database. Pass environment to any per-site tool to work on one of them.')]
class ListSiteEnvironments extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $main = $website->parent ?? $website;

        return Response::json([
            'website' => $main->slug,
            'environments' => collect([$main])->merge($main->environments()->get())
                ->map(fn (Website $environment): array => $this->environmentSummary($environment))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain (any of its environments works too).')->required(),
        ];
    }
}
