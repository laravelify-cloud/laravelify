<?php

namespace App\Mcp\Tools;

use App\Models\Group;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the groups (folders for websites, like one per product or client) with how many websites each has. A new website must go into a group.')]
class ListGroups extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::json(Group::query()->withCount('websites')->orderBy('name')->get()->map(fn (Group $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'enabled' => (bool) $group->enabled,
            'websites' => $group->websites_count,
        ])->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
