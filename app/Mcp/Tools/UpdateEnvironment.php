<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\HostWriter;
use App\Support\EnvFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Throwable;

#[IsIdempotent]
#[Description('Set or remove environment variables (.env) of a website. Other variables stay as they are. Values are written to the server right away; Laravel and PHP read them at once, but values a front-end build reads (NEXT_PUBLIC_, VITE_, …) need a new deploy.')]
class UpdateEnvironment extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, HostWriter $host): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $values = [];

        foreach ((array) $request->get('set', []) as $line) {
            [$key, $value] = array_pad(explode('=', (string) $line, 2), 2, null);

            if ($value === null) {
                throw ValidationException::withMessages(['set' => 'Write each variable as KEY=value ("'.$line.'" is not).']);
            }

            $values[trim((string) $key)] = $value;
        }

        foreach ((array) $request->get('unset', []) as $key) {
            $values[trim((string) $key)] = null;
        }

        if ($values === []) {
            throw ValidationException::withMessages(['set' => 'Give variables to set ("KEY=value") or names to unset.']);
        }

        try {
            $content = EnvFile::set((string) $website->env_content, $values);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['set' => $exception->getMessage()]);
        }

        $website->update(['env_content' => $content]);
        $applied = true;

        try {
            $host->writeWebsite($website);
        } catch (Throwable $exception) {
            $applied = false;
            $error = $exception->getMessage();
        }

        return Response::json([
            'set' => array_keys(array_filter($values, fn ($value) => $value !== null)),
            'removed' => array_keys(array_filter($values, fn ($value) => $value === null)),
            'written_to_server' => $applied,
            'error' => $error ?? null,
            'next' => $website->isPhp() ? 'PHP reads the new values on the next request.' : 'Deploy again (deploy-website) so the app and its build use the new values.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'set' => $schema->array()->items($schema->string())->description('Variables to set, as "KEY=value" strings.'),
            'unset' => $schema->array()->items($schema->string())->description('Variable names to remove.'),
        ];
    }
}
