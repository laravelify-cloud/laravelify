<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\SitePaths;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[Description('List the files and folders of a website\'s folder (or one folder inside it), folders first. Use read-file to read one.')]
class ListFiles extends Tool
{
    use DescribesWebsites;

    private const MAX_ENTRIES = 300;

    public function handle(Request $request, SitePaths $paths): Response
    {
        $website = $this->findWebsite($request->get('website'));

        try {
            $root = $paths->root($website);
            $folder = $paths->resolveIn($root, (string) $request->get('path', ''));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        if (! is_dir($folder)) {
            throw ValidationException::withMessages(['path' => 'That is not a folder.']);
        }

        $entries = [];

        foreach (scandir($folder) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $folder.DIRECTORY_SEPARATOR.$name;
            $type = is_link($full) ? 'link' : (is_dir($full) ? 'dir' : 'file');
            $entries[] = [
                'name' => $name,
                'type' => $type,
                'size' => $type === 'file' ? (int) @filesize($full) : null,
                'modified' => ($time = @filemtime($full)) ? date('c', $time) : null,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$a['type'] === 'dir' ? 0 : 1, strtolower($a['name'])] <=> [$b['type'] === 'dir' ? 0 : 1, strtolower($b['name'])]);

        return Response::json([
            'website' => $website->slug,
            'path' => $paths->relative($root, $folder),
            'total' => count($entries),
            'entries' => array_slice($entries, 0, self::MAX_ENTRIES),
            'truncated' => count($entries) > self::MAX_ENTRIES,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'path' => $schema->string()->description('A folder inside the site, like storage/app. Empty: the site folder.'),
        ];
    }
}
