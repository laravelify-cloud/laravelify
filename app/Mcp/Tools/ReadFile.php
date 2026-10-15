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
#[Description('Read a text file of a website (up to 200 KB at a time, with offset for more). Environment files are not readable here: use get-environment, which hides secret values. Never repeat secrets from files back unless the user asked.')]
class ReadFile extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, SitePaths $paths): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $relative = trim((string) $request->get('path'));

        if (preg_match('/(^|\/)\.env[^\/]*$/', $relative) === 1) {
            throw ValidationException::withMessages(['path' => 'Environment files are read with get-environment, which hides secret values.']);
        }

        try {
            $file = $paths->resolveIn($paths->root($website), $relative);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        if (is_link($file) || ! is_file($file)) {
            throw ValidationException::withMessages(['path' => 'That is not a file.']);
        }

        $offset = max(0, (int) $request->get('offset', 0));
        $size = (int) filesize($file);
        $content = (string) file_get_contents($file, false, null, $offset, 200000);

        if (str_contains($content, "\0")) {
            throw ValidationException::withMessages(['path' => 'That looks like a binary file ('.$size.' bytes), so it is not shown.']);
        }

        return Response::json([
            'path' => $relative,
            'size' => $size,
            'offset' => $offset,
            'bytes_returned' => strlen($content),
            'more' => $offset + strlen($content) < $size ? 'Call again with offset '.($offset + strlen($content)) : null,
            'content' => $content,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'path' => $schema->string()->description('The file inside the site, like composer.json or storage/logs/laravel.log.')->required(),
            'offset' => $schema->integer()->description('Start at this byte, for files over 200 KB.'),
        ];
    }
}
