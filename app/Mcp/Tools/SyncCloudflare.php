<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\CloudflareRoutes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Add all of a website\'s domains to its Cloudflare tunnel and DNS again (public hostname and proxied CNAME per domain). Use it after the user saved a Cloudflare API token, or when a domain does not open. Needs the token (Settings → Cloudflare) and the website on a tunnel; a DNS record that already exists for something else is reported, not replaced.')]
class SyncCloudflare extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, CloudflareRoutes $cloudflare): Response
    {
        $website = $this->findWebsite($request->get('website'));

        if (! $website->cloudflare_tunnel_id) {
            return Response::json(['website' => $website->slug, 'note' => 'This website is not on a Cloudflare tunnel. Set one with update-website (cloudflare_tunnel_id) or in the panel.']);
        }

        $report = $cloudflare->sync($website);

        return Response::json([
            'website' => $website->slug,
            'configured' => $report['configured'],
            'domains' => $report['lines'],
            'problems' => CloudflareRoutes::problems($report),
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
