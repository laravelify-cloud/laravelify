<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\CloudflareRoutes;
use App\Services\WebsiteDomains;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Set the domains of a website: one main domain plus any number of extra domains (www, a second domain) that open the same site, so one app needs no second website. nginx answers on all of them; on a Cloudflare tunnel they are added to the tunnel and DNS when a Cloudflare API token is saved (otherwise the result says what to add in Cloudflare). Pass the complete list of extra domains: the ones you leave out are removed.')]
class SetDomains extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, WebsiteDomains $domains): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $domain = $request->get('domain') !== null ? (WebsiteDomains::normalize((string) $request->get('domain'))[0] ?? null) : $website->domain;
        $aliases = $request->get('aliases') !== null ? WebsiteDomains::normalize((array) $request->get('aliases')) : (array) ($website->aliases ?? []);

        if ($request->get('include_www', false) && $domain !== null) {
            $aliases[] = WebsiteDomains::wwwOf($domain);
        }

        if ($website->cloudflare_tunnel_id !== null && $domain === null) {
            throw ValidationException::withMessages(['domain' => 'A website on a Cloudflare tunnel needs a main domain.']);
        }

        $report = $domains->apply($website, $domain, array_values(array_unique($aliases)));
        $website->refresh();

        return Response::json([
            'website' => $website->slug,
            'domain' => $website->domain,
            'aliases' => $website->aliases ?? [],
            'urls' => array_map(fn (string $host): string => ($website->cloudflare_tunnel_id ? 'https' : 'http').'://'.$host, $website->domains()),
            'cloudflare' => $report['lines'],
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
            'domain' => $schema->string()->description('The main domain, like example.com. Left out: unchanged.'),
            'aliases' => $schema->array()->items($schema->string())->description('All extra domains, like ["www.example.com", "other.com"]. Left out: unchanged; [] removes them all.'),
            'include_www' => $schema->boolean()->description('Also add www.<main domain> to the extra domains.'),
        ];
    }
}
