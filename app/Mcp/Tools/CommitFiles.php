<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\RepositoryWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Change a website\'s code: write whole files into its Git repository as one commit on its branch, push it, and start a deploy. Send each file\'s complete new contents (read it first with read-file). Up to 20 text files, 1 MB in all. Use a clear commit message. Afterwards follow the deploy with get-deployment and check the site with check-website. Never put secrets in code.')]
class CommitFiles extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, RepositoryWriter $writer): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $files = [];

        foreach ((array) $request->get('files', []) as $file) {
            if (! is_array($file) || ! isset($file['path']) || ! array_key_exists('content', $file)) {
                throw ValidationException::withMessages(['files' => 'Each file needs a path and its content.']);
            }

            if (preg_match('/(^|\/)\.env[^\/]*$/', (string) $file['path']) === 1) {
                throw ValidationException::withMessages(['files' => 'Environment files are changed with update-environment, never committed.']);
            }

            $files[(string) $file['path']] = (string) $file['content'];
        }

        try {
            $result = $writer->commit($website, $files, (string) $request->get('message', ''));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['files' => $exception->getMessage()]);
        }

        return Response::json($result + [
            'website' => $website->name,
            'branch' => $website->branch,
            'next' => $result['deploying'] ? 'Deploying: follow it with get-deployment, then check-website.' : 'Run deploy-website to put it live.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'message' => $schema->string()->description('The commit message: what was fixed and why.')->required(),
            'files' => $schema->array()->items($schema->object([
                'path' => $schema->string()->description('Path in the repository, like app/Http/Controllers/CartController.php.')->required(),
                'content' => $schema->string()->description('The whole new contents of the file.')->required(),
            ]))->description('The files to write, each with its complete new contents.')->required(),
        ];
    }
}
