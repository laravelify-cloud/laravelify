<?php

namespace App\Mcp\Tools;

use App\Models\CloudflareTunnel;
use App\Models\Plugin;
use App\Services\PluginManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Open an installed plugin on a domain through nginx (and the Cloudflare tunnel, for HTTPS). kind "page" is its web page (MinIO console, Vault UI) behind basic auth: it needs basic_auth_user and a basic_auth_password of 12+ characters, which you must ask the user for (they are stored hashed and never returned). kind "api" is MinIO\'s S3 API on a public domain without a password (image links and S3 clients; MinIO\'s keys decide who can write). With remove true the domain is taken off. Returns what Cloudflare said; tell the user any problem.')]
class SetPluginDomain extends Tool
{
    public function handle(Request $request, PluginManager $manager): Response
    {
        $plugin = Plugin::query()->where('key', (string) $request->get('plugin'))->first()
            ?? throw ValidationException::withMessages(['plugin' => 'That plugin is not installed. Use list-plugins.']);
        $kind = (string) $request->get('kind', 'page');

        if (! in_array($kind, ['page', 'api'], true)) {
            throw ValidationException::withMessages(['kind' => 'kind is page (its web page, behind basic auth) or api (MinIO\'s S3 API, public).']);
        }

        try {
            if ($request->get('remove', false)) {
                $kind === 'api' ? $manager->removeApiDomain($plugin) : $manager->removeDomain($plugin);

                return Response::json(['plugin' => $plugin->key, 'kind' => $kind, 'domain' => null]);
            }

            $tunnelId = $request->get('cloudflare_tunnel_id');
            $tunnel = $tunnelId === null ? null : (CloudflareTunnel::query()->find((int) $tunnelId)
                ?? throw ValidationException::withMessages(['cloudflare_tunnel_id' => 'No such tunnel. Use list-sources.']));
            $domain = (string) $request->get('domain');

            $report = $kind === 'api'
                ? $manager->setApiDomain($plugin, $domain, $tunnel)
                : $manager->setDomain($plugin, $domain, $tunnel, (string) $request->get('basic_auth_user', 'admin'), (string) $request->get('basic_auth_password', ''));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['domain' => $manager->redact($plugin, $exception->getMessage())]);
        }

        $plugin->refresh();

        return Response::json([
            'plugin' => $plugin->key,
            'kind' => $kind,
            'domain' => $kind === 'api' ? $plugin->api_domain : $plugin->domain,
            'basic_auth_user' => $kind === 'page' ? $plugin->basic_auth_user : null,
            'cloudflare' => $report,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'plugin' => $schema->string()->description('The plugin key: minio or vault.')->required(),
            'kind' => $schema->string()->description('page (its web page, behind basic auth) or api (MinIO\'s S3 API, public). Default page.'),
            'domain' => $schema->string()->description('Like s3.example.com. Not needed with remove.'),
            'cloudflare_tunnel_id' => $schema->integer()->description('Serve it over HTTPS through this Cloudflare tunnel (list-sources).'),
            'basic_auth_user' => $schema->string()->description('kind page: the user name (default admin).'),
            'basic_auth_password' => $schema->string()->description('kind page: 12+ characters, from the user. Stored hashed, never returned.'),
            'remove' => $schema->boolean()->description('Take the domain off instead.'),
        ];
    }
}
