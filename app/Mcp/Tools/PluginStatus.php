<?php

namespace App\Mcp\Tools;

use App\Models\Plugin;
use App\Services\PluginManager;
use App\Support\Plugins;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The state of an installed plugin, read from Docker now: running, stopped, or error (with the error), its 127.0.0.1 ports, its domain, the linked websites, and for Vault whether it is sealed. With logs true, also its last 100 log lines (passwords blanked). Never returns passwords.')]
class PluginStatus extends Tool
{
    public function handle(Request $request, PluginManager $manager): Response
    {
        $key = (string) $request->get('plugin');
        Plugins::get($key) ?? throw ValidationException::withMessages(['plugin' => 'No such plugin. Use list-plugins.']);
        $plugin = Plugin::query()->where('key', $key)->first()
            ?? throw ValidationException::withMessages(['plugin' => $key.' is not installed. Use install-plugin.']);
        $plugin = $manager->refresh($plugin);
        $sealable = $plugin->definition()->sealable();

        return Response::json([
            'plugin' => $key,
            'status' => $plugin->status,
            'error' => $plugin->status === Plugin::ERROR ? $plugin->last_error : null,
            'ports' => collect($plugin->ports)->map(fn (int $port): string => '127.0.0.1:'.$port)->all(),
            'domain' => $plugin->domain,
            'sealed' => $sealable ? $plugin->sealed : null,
            'auto_unseal' => $sealable ? $plugin->auto_unseal : null,
            'linked_websites' => $plugin->websites()->pluck('slug')->all(),
            'logs' => $request->get('logs', false) ? $manager->logs($plugin, 100) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'plugin' => $schema->string()->description('The plugin key: postgres, redis, minio, or vault.')->required(),
            'logs' => $schema->boolean()->description('Also return its last 100 log lines.'),
        ];
    }
}
