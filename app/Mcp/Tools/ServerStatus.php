<?php

namespace App\Mcp\Tools;

use App\Services\ServerStats;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('How the server is doing right now: CPU load, memory, disk, uptime, the state of nginx, MySQL, Redis, Supervisor, PHP-FPM, and the Cloudflare tunnel, and the busiest processes. Use it when sites are slow or a deploy fails for no clear reason (a full disk is a common cause).')]
class ServerStatus extends Tool
{
    public function handle(Request $request, ServerStats $stats): Response
    {
        $snapshot = $stats->snapshot();

        return Response::json([
            'server' => $snapshot,
            'services' => $stats->services(),
            'top_processes' => $stats->topProcesses(5),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
