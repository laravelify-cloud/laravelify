<?php

namespace App\Mcp\Tools;

use App\Models\Plugin;
use App\Plugins\PluginDefinition;
use App\Support\Plugins;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the plugins this panel offers (PostgreSQL, Redis, MinIO, HashiCorp Vault) and which are installed: services run with Docker on 127.0.0.1 that a website uses after link-plugin. Never returns passwords.')]
class ListPlugins extends Tool
{
    public function handle(Request $request): Response
    {
        $installed = Plugin::query()->get()->keyBy('key');

        return Response::json([
            'plugins' => collect(Plugins::all())->map(fn (PluginDefinition $definition, string $key): array => [
                'key' => $key,
                'label' => $definition->label(),
                'description' => $definition->description(),
                'status' => $installed->get($key)?->status ?? 'not installed',
            ])->values()->all(),
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
