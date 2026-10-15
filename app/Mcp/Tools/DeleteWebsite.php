<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\WebsiteSwitch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a website from the panel: its nginx, worker, and PM2 files go, its deployments and settings are deleted, and its domains are taken off the Cloudflare tunnel (and their CNAMEs deleted when a Cloudflare token is saved). The folder in /var/www and the MySQL database stay, so nothing is lost for good. Ask the user first, and pass the website\'s slug as confirm.')]
class DeleteWebsite extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, WebsiteSwitch $switch): Response
    {
        $website = $this->findWebsite($request->get('website'));

        if (trim((string) $request->get('confirm')) !== $website->slug) {
            throw ValidationException::withMessages(['confirm' => 'To delete '.$website->name.', pass its slug "'.$website->slug.'" as confirm.']);
        }

        $folder = $website->deploy_path;
        $database = $website->provision_database ? $website->db_name : null;
        $switch->delete($website);

        return Response::json([
            'deleted' => $website->slug,
            'kept' => array_values(array_filter([
                $folder ? 'the folder '.$folder.' on the server' : null,
                $database ? 'the MySQL database '.$database : null,
            ])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'confirm' => $schema->string()->description('The website\'s slug, to confirm.')->required(),
        ];
    }
}
