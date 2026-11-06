<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\GlitchtipInsights;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[IsReadOnly]
#[Description('A website\'s open errors from GlitchTip, newest first: exception, message, where it was thrown, how often, and (with details: true) the latest stack trace with the code around each line. Use it to find what to fix; read-file shows more of a file.')]
class ListIssues extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, GlitchtipInsights $insights): Response
    {
        $website = $this->findWebsite($request->get('website'));

        if (! $insights->available()) {
            throw ValidationException::withMessages(['website' => 'GlitchTip is not on this server. Turn it on in a website\'s GlitchTip tab.']);
        }

        if (! $website->glitchtip_project_id) {
            throw ValidationException::withMessages(['website' => $website->name.' is not connected to GlitchTip yet: connect it in its GlitchTip tab.']);
        }

        $page = $insights->issues([
            'project' => (int) $website->glitchtip_project_id,
            'period' => in_array($request->get('period'), array_keys(GlitchtipInsights::PERIODS), true) ? $request->get('period') : '14d',
            'status' => 'unresolved',
            'query' => (string) $request->get('query', ''),
        ], null, min(25, max(1, (int) $request->get('limit', 10))));

        $issues = array_map(function (array $issue) use ($request, $insights) {
            if ($request->get('details', false)) {
                try {
                    $event = $insights->latestEvent($issue['id']);
                    $issue['latest'] = [
                        'message' => $event['message'],
                        'request' => $event['request'],
                        'frames' => array_slice($event['frames'], 0, 12),
                    ];
                } catch (Throwable) {
                    $issue['latest'] = null;
                }
            }

            unset($issue['trend']);

            return $issue;
        }, $page['rows']);

        return Response::json(['website' => $website->name, 'open_issues' => $page['total'] ?? count($issues), 'issues' => $issues]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'details' => $schema->boolean()->description('Add each issue\'s latest stack trace with code lines. Slower: use a small limit.'),
            'limit' => $schema->integer()->description('How many issues, 1 to 25 (default 10).'),
            'period' => $schema->string()->description('1h, 24h, 7d, 14d (default) or 30d.'),
            'query' => $schema->string()->description('Only issues whose message, exception or file contains this.'),
        ];
    }
}
