<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\HostWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description(<<<'TEXT'
    Replace a website's nginx server block (get it first with get-nginx and change only what is needed). nginx checks it before
    reloading; when it is rejected, the previous file is put back and the error is returned. Keep the listen and server_name lines.
    To send some paths to another site on this server (like OAuth or payment callbacks handled by a Laravel API), add for example:
    location /google/callback { proxy_pass http://127.0.0.1; proxy_set_header Host api.example.com; proxy_set_header X-Forwarded-Proto https; proxy_set_header X-Real-IP $remote_addr; proxy_set_header CF-Connecting-IP $http_cf_connecting_ip; }
    TEXT)]
class UpdateNginx extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, HostWriter $host): Response
    {
        $request->validate(['nginx_config' => ['required', 'string', 'max:100000']]);
        $website = $this->findWebsite($request->get('website'));
        $config = (string) $request->get('nginx_config');

        if (! str_contains($config, 'server_name') || ! str_contains($config, 'listen')) {
            throw ValidationException::withMessages(['nginx_config' => 'Keep the listen and server_name lines of the site.']);
        }

        $previous = (string) $website->nginx_config;
        $website->update(['nginx_config' => $config]);

        try {
            $host->writeWebsite($website);
        } catch (Throwable $exception) {
            $website->update(['nginx_config' => $previous]);

            try {
                $host->writeWebsite($website);
            } catch (Throwable) {
                // The previous file was live before; nginx keeps serving it.
            }

            throw ValidationException::withMessages(['nginx_config' => 'nginx rejected it, so the previous file was put back: '.$exception->getMessage()]);
        }

        return Response::json(['website' => $website->slug, 'applied' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'nginx_config' => $schema->string()->description('The whole server block, as get-nginx returns it, with your change.')->required(),
        ];
    }
}
