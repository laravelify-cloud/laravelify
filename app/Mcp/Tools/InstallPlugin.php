<?php

namespace App\Mcp\Tools;

use App\Services\PanelTaskRunner;
use App\Support\Plugins;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Install a plugin (postgres, redis, minio, vault) in the background: Docker first when missing, then the service on free 127.0.0.1 ports with strong generated passwords (kept encrypted; the user sees them once on the Plugins page, they are never returned here). Vault is initialized and unsealed. Installing again keeps its data, ports, and passwords. Follow it with get-task, then link-plugin to give a website its variables.')]
class InstallPlugin extends Tool
{
    public function handle(Request $request, PanelTaskRunner $tasks): Response
    {
        $key = (string) $request->get('plugin');
        $definition = Plugins::get($key) ?? throw ValidationException::withMessages(['plugin' => 'No such plugin. Use list-plugins.']);

        if (PanelTaskRunner::running('plugins.install', 'plugin:'.$key)) {
            throw ValidationException::withMessages(['plugin' => $definition->label().' is already being installed.']);
        }

        $task = $tasks->start('plugins.install', 'plugin:'.$key, 'Installing '.$definition->label(), ['key' => $key]);

        return Response::json([
            'task_id' => $task->id,
            'plugin' => $key,
            'next' => 'Call get-task with task_id '.$task->id.' every 10–20 seconds until finished is true, then plugin-status.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'plugin' => $schema->string()->description('The plugin key: postgres, redis, minio, or vault.')->required(),
        ];
    }
}
