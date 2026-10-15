<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\SiteLogs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[Description('Read a website\'s logs: the Laravel log, queue and worker logs, PM2 output, other log files, and nginx\'s access and error log. Call it without source to list the logs of the site (key, label, size), then again with a key. Use it when a site shows an error or a deploy succeeded but the page is broken.')]
class GetLogs extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, SiteLogs $logs): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $sources = $logs->sources($website);
        $source = trim((string) $request->get('source', ''));

        if ($source === '') {
            return Response::json([
                'website' => $website->slug,
                'logs' => array_map(fn (array $item) => ['key' => $item['key'], 'label' => $item['label'], 'group' => $item['group'], 'size' => $item['size']], $sources),
                'next' => 'Call get-logs again with a key as source.',
            ]);
        }

        if (! in_array($source, array_column($sources, 'key'), true)) {
            throw ValidationException::withMessages(['source' => 'No log "'.$source.'". Call get-logs without source to see the keys.']);
        }

        try {
            $text = $logs->tail($website, $source, max(10, min(500, (int) $request->get('lines', 100))));
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['source' => $exception->getMessage()]);
        }

        return Response::text($text !== '' ? $text : '(this log is empty or does not exist yet)');
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'source' => $schema->string()->description('A log key from the list, like laravel, pm2, nginx-error.'),
            'lines' => $schema->integer()->description('Lines from the end (10 to 500, default 100).'),
        ];
    }
}
