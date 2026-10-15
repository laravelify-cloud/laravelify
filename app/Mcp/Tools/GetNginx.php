<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Show a website\'s nginx server block, to change it with update-nginx.')]
class GetNginx extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request): Response
    {
        $website = $this->findWebsite($request->get('website'));

        return Response::text((string) $website->nginx_config);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
        ];
    }
}
