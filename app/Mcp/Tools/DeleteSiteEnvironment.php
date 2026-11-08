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

#[Description('Delete an environment of a website, like development. Production (the main website) cannot be deleted here. Its folder moves to the trash and its database stays on the server. Ask the user first.')]
class DeleteSiteEnvironment extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, WebsiteSwitch $switch): Response
    {
        $website = $this->findEnvironment($this->findWebsite($request->get('website')), (string) $request->get('environment'));

        if ($website->parent_id === null) {
            throw ValidationException::withMessages(['environment' => 'That is production, the last environment every website keeps. Delete the whole website with delete-website instead.']);
        }

        $folder = $website->deploy_path;
        $database = $website->provision_database ? $website->db_name : null;
        $name = $website->environment;
        $switch->delete($website);

        return Response::json([
            'deleted' => $name,
            'kept' => array_values(array_filter([
                $folder ? 'the folder '.$folder.' (moved to the trash)' : null,
                $database ? 'the database '.$database : null,
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
            'environment' => $schema->string()->description('The environment id or name to delete (list-site-environments).')->required(),
        ];
    }
}
