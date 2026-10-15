<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\ServerProcesses;
use App\Services\SiteLogs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Restart a website\'s processes without deploying: the PM2 app of a Node or Next.js site, or the Supervisor workers (queue, scheduler, Reverb) of a Laravel site. PHP and static sites have nothing to restart; nginx serves them. Use it after changing .env on a Node site or when a worker is stuck.')]
class RestartWebsite extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, ServerProcesses $processes, SiteLogs $logs): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $results = [];

        try {
            if ($website->isNode()) {
                $results['pm2:'.$website->slug] = trim($processes->restart('pm2', $website->slug));
            } else {
                foreach (array_keys($logs->programs($website)) as $program) {
                    $results['supervisor:'.$program] = trim($processes->restart('supervisor', $program));
                }
            }
        } catch (Throwable $exception) {
            $results['error'] = $exception->getMessage();
        }

        return Response::json([
            'website' => $website->slug,
            'restarted' => $results,
            'note' => $results === [] ? 'This site has no processes to restart: PHP and static sites are served by nginx.' : null,
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
