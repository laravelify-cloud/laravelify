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
        $website = $this->findSite($request);
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

        if (! $reveal) {
            $variables = $this->hideSecretsInsideValues($variables, $content);
        }

        return Response::json([
            'website' => $website->slug,
            'count' => count($variables),
            'variables' => $variables,
            'hidden' => ! $reveal ? array_keys(array_filter($variables, fn ($value) => $value === '********')) : [],
        ]);
    }

    /**
     * A secret also hides inside other values: the password of a URL (DATABASE_URL, REDIS_URL,
     * postgresql://app:secret@host) and any value that contains a hidden variable's value.
     *
     * @param  array<string, string|null>  $variables
     * @return array<string, string|null>
     */
    private function hideSecretsInsideValues(array $variables, string $content): array
    {
        $secrets = [];

        foreach (EnvFile::values($content, EnvFile::keys($content)) as $key => $value) {
            if (is_string($value) && strlen($value) >= 6 && preg_match(self::SECRET, $key) === 1) {
                $secrets[] = $value;
            }
        }

        foreach ($variables as $key => $value) {
            if ($value === null || $value === '********') {
                continue;
            }

            $value = (string) preg_replace('#(://[^:/@\s]*:)[^@\s]+@#', '$1********@', $value);
            $variables[$key] = str_replace($secrets, '********', $value);
        }

        return $variables;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'environment' => $this->environmentSchema($schema),
            'keys' => $schema->array()->items($schema->string())->description('Only these variables.'),
            'reveal' => $schema->boolean()->description('Show secret values too.'),
        ];
    }
}
