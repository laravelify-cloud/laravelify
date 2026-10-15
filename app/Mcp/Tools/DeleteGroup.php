<?php

namespace App\Mcp\Tools;

use App\Models\Group;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete an empty group. A group that still has websites is refused: delete or move its websites first.')]
class DeleteGroup extends Tool
{
    public function handle(Request $request): Response
    {
        $reference = trim((string) $request->get('group'));
        $group = (ctype_digit($reference) ? Group::query()->find((int) $reference) : Group::query()->where('name', $reference)->first())
            ?? throw ValidationException::withMessages(['group' => 'No group "'.$reference.'". list-groups shows them.']);

        $count = $group->websites()->count();

        if ($count > 0) {
            throw ValidationException::withMessages(['group' => $group->name.' still has '.$count.' '.($count === 1 ? 'website' : 'websites').'. Delete them first (delete-website).']);
        }

        $group->delete();

        return Response::json(['deleted' => $group->name]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->string()->description('The group id or name.')->required(),
        ];
    }
}
