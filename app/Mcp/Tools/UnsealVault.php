<?php

namespace App\Mcp\Tools;

use App\Models\Plugin;
use App\Services\PluginManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Unseal the Vault plugin with the unseal keys the panel keeps (encrypted). Vault seals itself on every restart, and apps get errors from it until it is unsealed. Use it when plugin-status says sealed true. The keys are never returned.')]
class UnsealVault extends Tool
{
    public function handle(Request $request, PluginManager $manager): Response
    {
        $plugin = Plugin::query()->where('key', 'vault')->first()
            ?? throw ValidationException::withMessages(['plugin' => 'Vault is not installed. Use install-plugin with vault.']);

        try {
            $open = $manager->unseal($plugin);
        } catch (Throwable $exception) {
            return Response::error($manager->redact($plugin, $exception->getMessage()));
        }

        return Response::json([
            'sealed' => ! $open,
            'note' => $open ? 'Vault is unsealed.' : 'Vault is still sealed. Check plugin-status with logs true.',
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
