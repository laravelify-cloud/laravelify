<?php

namespace App\Mcp\Tools;

use App\Models\Group;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Rename a group or change its description.')]
class UpdateGroup extends Tool
{
    public function handle(Request $request): Response
    {
        $group = $this->group((string) $request->get('group'));
        $given = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($given === []) {
            throw ValidationException::withMessages(['name' => 'Give a new name or description.']);
        }

        $group->update($given);

        return Response::json(['id' => $group->id, 'name' => $group->name, 'description' => $group->description]);
    }

    private function group(string $reference): Group
    {
        $reference = trim($reference);

        return (ctype_digit($reference) ? Group::query()->find((int) $reference) : Group::query()->where('name', $reference)->first())
            ?? throw ValidationException::withMessages(['group' => 'No group "'.$reference.'". list-groups shows them.']);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->string()->description('The group id or name.')->required(),
            'name' => $schema->string()->description('The new name.'),
            'description' => $schema->string()->description('The new description.'),
        ];
    }
}
