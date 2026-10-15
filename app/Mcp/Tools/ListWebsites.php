<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the websites with their address, repository, branch, on/off state, and last deployment. Optionally only one group.')]
class ListWebsites extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $group = $request->get('group_id');

        return Response::json(Website::query()->with('group')
            ->when($group, fn ($query) => $query->where('group_id', (int) $group))
            ->orderBy('name')->get()
            ->map(fn (Website $website) => $this->websiteSummary($website))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group_id' => $schema->integer()->description('Only the websites of this group.'),
        ];
    }
}
