<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\SiteFileWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Put a file the repository does not carry into a website\'s folder, like a Firebase key in storage/app/private, or unpack a .zip / .tar.gz / .tgz archive into a folder. Send the file base64-encoded (up to 20 MB). Deploys keep these files. Use it after the first deploy has finished (the folder is created by it). Never print the file\'s contents back.')]
class UploadFile extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, SiteFileWriter $writer): Response
    {
        $request->validate([
            'path' => ['required', 'string', 'max:500'],
            'content_base64' => ['required', 'string'],
        ]);

        $website = $this->findWebsite($request->get('website'));
        $contents = base64_decode(preg_replace('/\s+/', '', (string) $request->get('content_base64')) ?? '', true);

        if ($contents === false) {
            throw ValidationException::withMessages(['content_base64' => 'content_base64 is not valid base64.']);
        }

        try {
            if ($request->get('unpack', false)) {
                $files = $writer->extract($website, (string) $request->get('path'), $contents, (string) $request->get('filename', ''));

                return Response::json([
                    'website' => $website->slug,
                    'unpacked_into' => trim((string) $request->get('path'), '/') ?: '(site folder)',
                    'files' => $files,
                ]);
            }

            $path = $writer->write($website, (string) $request->get('path'), $contents, (bool) $request->get('overwrite', true));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        return Response::json([
            'website' => $website->slug,
            'written' => $path,
            'bytes' => strlen($contents),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'path' => $schema->string()->description('Inside the site folder. The file to write, like storage/app/private/firebase-adminsdk.json; or with unpack, the folder to unpack into, like storage/app/private.')->required(),
            'content_base64' => $schema->string()->description('The file (or archive) contents, base64-encoded.')->required(),
            'unpack' => $schema->boolean()->description('Unpack an archive into path instead of writing one file.'),
            'filename' => $schema->string()->description('With unpack: the archive\'s file name, so its type is known (like private.tgz or files.zip).'),
            'overwrite' => $schema->boolean()->description('Replace a file that is already there (default true).'),
        ];
    }
}
