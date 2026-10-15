<?php

namespace App\Mcp\Tools;

use App\Support\Examples;
use App\Support\SiteTypes;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the kinds of website Laravelify can deploy (the "type" for create-website), how each runs, and the PHP and Node versions available. Read package.json or composer.json of the project to pick the right type.')]
class ListSiteTypes extends Tool
{
    public function handle(Request $request): Response
    {
        $types = collect(SiteTypes::all())->map(fn (array $definition, string $key) => [
            'type' => $key,
            'label' => $definition['label'],
            'runtime' => $definition['runtime'],
            'how_it_runs' => match ($definition['runtime']) {
                SiteTypes::RUNTIME_PHP => 'PHP-FPM behind nginx (php_version applies)'.($key === 'laravel' ? '; queue and scheduler workers under Supervisor' : ''),
                SiteTypes::RUNTIME_NODE => 'A Node server under PM2 behind nginx (node_version, install/build/start commands apply)',
                default => $key === 'static' ? 'Plain files served by nginx' : 'Built on the server with Node (node_version, install/build commands, output_dir apply), then served as files by nginx',
            },
            'best_for' => $definition['best'] ?? null,
            'has_example_project' => Examples::has($key),
        ])->values()->all();

        return Response::json([
            'types' => $types,
            'php_versions' => config('laravelify.php_versions'),
            'node_versions' => config('laravelify.node_versions'),
            'notes' => 'Leave install, build, and start commands empty to use the defaults for the type (npm, pnpm, or yarn is picked from the lock file).',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
