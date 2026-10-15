<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\FileTrash;
use App\Services\SitePaths;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use RuntimeException;

#[IsDestructive]
#[Description('Delete a file or folder of a website. It goes to the panel\'s Trash (File manager → Trash), where the user can restore it, so nothing is lost for good. Ask first for anything but your own mistakes.')]
class DeleteFile extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, FileTrash $trash, SitePaths $paths): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $relative = trim((string) $request->get('path'), '/');

        if ($relative === '') {
            throw ValidationException::withMessages(['path' => 'Give a file or folder inside the site.']);
        }

        try {
            $trash->move('files:website:'.$website->id, $paths->root($website), [$relative], $request->user()?->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        return Response::json(['deleted' => $relative, 'website' => $website->slug, 'restore' => 'In the panel: the site\'s Files → Trash.']);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'path' => $schema->string()->description('The file or folder inside the site.')->required(),
        ];
    }
}
