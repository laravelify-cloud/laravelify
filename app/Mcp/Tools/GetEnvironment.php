<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Support\EnvFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show a website\'s environment variables. Values of secret-looking variables (keys, passwords, tokens, secrets) are hidden as ******** unless reveal is true; reveal only when the user asked to see them, and never repeat them back otherwise.')]
class GetEnvironment extends Tool
{
    use DescribesWebsites;

    private const SECRET = '/(KEY|SECRET|PASSWORD|PASSWD|TOKEN|PRIVATE|CREDENTIAL|DSN|SALT|SIGNATURE|CERT|AUTH)/i';

    public function handle(Request $request): Response
    {
        $website = $this->findWebsite($request->get('website'));
        $reveal = (bool) $request->get('reveal', false);
        $wanted = array_filter(array_map('trim', (array) $request->get('keys', [])));
        $content = (string) $website->env_content;
        $keys = $wanted !== [] ? array_values($wanted) : EnvFile::keys($content);
        $values = EnvFile::values($content, $keys);
        $variables = [];

        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            $variables[$key] = $value === null ? null : ($value !== '' && ! $reveal && preg_match(self::SECRET, $key) === 1 ? '********' : $value);
        }

        return Response::json([
            'website' => $website->slug,
            'count' => count($variables),
            'variables' => $variables,
            'hidden' => ! $reveal ? array_keys(array_filter($variables, fn ($value) => $value === '********')) : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'keys' => $schema->array()->items($schema->string())->description('Only these variables.'),
            'reveal' => $schema->boolean()->description('Show secret values too.'),
        ];
    }
}
