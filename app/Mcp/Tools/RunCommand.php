<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\SiteCommand;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Run one command in a website\'s folder as the web user (the same as the panel\'s Terminal tab), for example "php artisan migrate --force", "php artisan cache:clear", "composer show", "npm ls", or "pm2 list". It stops after about 75 seconds; long work belongs in a deploy. Prefer the specific tools (deploy, environment, logs) when one fits, and ask before commands that delete data.')]
class RunCommand extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, SiteCommand $commands): Response
    {
        $website = $this->findWebsite($request->get('website'));

        try {
            $result = $commands->run($website, (string) $request->get('command'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['command' => $exception->getMessage()]);
        }

        return Response::json([
            'website' => $website->slug,
            'ok' => $result->ok,
            'exit_code' => $result->exitCode,
            'output' => $result->output,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'command' => $schema->string()->description('One command, like "php artisan about".')->required(),
        ];
    }
}
