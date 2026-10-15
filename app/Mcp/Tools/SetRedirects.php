<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\CloudflareRoutes;
use App\Services\WebsiteRedirects;
use App\Support\Redirects;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Set the redirects of a website. A rule with a domain in "from" (apply.coupons) sends that whole domain to "to" (blank = this website\'s own address), keeping the path unless keep_path is false; the domain is added to the Cloudflare tunnel and DNS like the site\'s other domains. A rule with a path in "from" (/old-page) redirects that page ("match": "prefix" also covers everything under it) to a path or URL. Pass the complete list: rules you leave out are removed; [] removes all. nginx is tested and rolled back if it rejects the result.')]
class SetRedirects extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, WebsiteRedirects $redirects): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $report = $redirects->apply($website, (array) $request->get('redirects', []));
        $website->refresh();

        return Response::json([
            'website' => $website->slug,
            'redirects' => Redirects::normalize((array) ($website->redirects ?? [])),
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
            'redirects' => $schema->array()->items($schema->object([
                'from' => $schema->string()->description('A domain like apply.coupons, or a path like /old-page.')->required(),
                'to' => $schema->string()->description('A URL like https://supercell.apply.coupons, or for a path rule a path like /new-page. Blank on a domain rule: this website\'s own address.'),
                'code' => $schema->integer()->description('301 (default), 302, 307, or 308.'),
                'keep_path' => $schema->boolean()->description('Domain rules: keep the requested path and query (default true).'),
                'match' => $schema->string()->description('Path rules: "exact" (default) or "prefix".'),
                'via' => $schema->string()->description('Domain rules: "tunnel" (default; the domain goes through the Cloudflare tunnel) or "ip" (nginx answers on port 80 and Cloudflare gets a proxied A record to the server IP; needs port 80 open and no tunnel route).'),
            ]))->description('The complete list of redirect rules.')->required(),
        ];
    }
}
