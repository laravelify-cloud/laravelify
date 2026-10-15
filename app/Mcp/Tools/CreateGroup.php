<?php

namespace App\Mcp\Tools;

use App\Models\Group;
use App\Support\Slugs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a group to hold websites (for example one per product or client). Returns the existing group when one with the same name exists.')]
class CreateGroup extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $group = Group::query()->where('name', $validated['name'])->first() ?? Group::query()->create([
            'name' => $validated['name'],
            'slug' => Slugs::unique(Group::class, $validated['name']),
            'description' => $validated['description'] ?? null,
        ]);

        return Response::json([
            'id' => $group->id,
            'name' => $group->name,
            'created' => $group->wasRecentlyCreated,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The group name, like the product or client.')->required(),
            'description' => $schema->string()->description('Optional, one line.'),
        ];
    }
}
