<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\SiteHealth;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Check that a website is healthy: it answers over HTTP, its workers and processes run, nginx accepts its config, and the last deploy worked. Each check is ok, warn, fail, or unknown with a detail. Run it after a deploy.')]
class CheckWebsite extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, SiteHealth $health): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $checks = array_map(fn (array $check) => ['check' => $check['title'], 'status' => $check['status'], 'detail' => $check['detail'], 'log' => $check['log'] ?? null], $health->check($website));
        $statuses = array_column($checks, 'status');

        return Response::json([
            'website' => $website->slug,
            'overall' => in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok'),
            'checks' => $checks,
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
