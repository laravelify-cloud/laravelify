<?php

namespace App\Mcp\Tools;

use App\Services\ServerProcesses;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the ports in use on the server: services, every website\'s ports, and anything else listening now (with the process). New websites always get a free port by themselves; use this only to explain or check a port.')]
class ListPorts extends Tool
{
    public function handle(Request $request, ServerProcesses $processes): Response
    {
        $map = $processes->portMap();

        return Response::json([
            'ports' => array_map(fn (array $row) => [
                'port' => $row['port'],
                'used_by' => $row['owner'],
                'what' => $row['detail'],
                'listening_now' => $row['listening'],
                'reachable_from_outside' => $row['public'],
            ], $map['rows']),
            'next_free_node_port' => $map['next_free'],
            'checked_live' => $map['checked'],
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
