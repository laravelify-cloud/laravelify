<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\DescribesWebsites;
use App\Services\CloudflareRoutes;
use App\Services\WebsiteDomains;
use App\Support\GitInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Change an environment of a website (not production): its name, branch, domain, or other domains. Only what you pass changes. A new branch is used from the next deploy-website.')]
class UpdateSiteEnvironment extends Tool
{
    use DescribesWebsites;

    public function handle(Request $request, WebsiteDomains $domains): Response
    {
        $website = $this->findEnvironment($this->findWebsite($request->get('website')), (string) $request->get('environment'));
        $main = $website->parent;

        if ($main === null) {
            throw ValidationException::withMessages(['environment' => 'That is production, the main website. Change it with update-website and set-domains.']);
        }

        if (($name = $request->get('name')) !== null) {
            $name = strtolower(trim((string) $name));

            if (! preg_match('/^[a-z0-9][a-z0-9-]{0,38}$/', $name) || $name === 'production') {
                throw ValidationException::withMessages(['name' => 'Use a short name with letters, numbers and dashes, like development or staging.']);
            }

            if ($main->environments()->where('environment', $name)->whereKeyNot($website->id)->exists()) {
                throw ValidationException::withMessages(['name' => $main->name.' already has a '.$name.' environment.']);
            }

            $website->update(['environment' => $name, 'name' => $main->name.' ('.$name.')']);
        }

        if (($branch = $request->get('branch')) !== null) {
            if (! GitInput::validBranch((string) $branch)) {
                throw ValidationException::withMessages(['branch' => 'That is not a branch name.']);
            }

            $website->update(['branch' => trim((string) $branch)]);
        }

        $report = null;

        if ($request->get('domain') !== null || $request->get('aliases') !== null) {
            $domain = $request->get('domain') !== null ? (WebsiteDomains::normalize((string) $request->get('domain'))[0] ?? null) : $website->domain;
            $aliases = $request->get('aliases') !== null ? WebsiteDomains::normalize((array) $request->get('aliases')) : (array) ($website->aliases ?? []);
            $report = $domains->apply($website, $domain, array_values(array_unique($aliases)));
        }

        return Response::json([
            'environment' => $this->environmentSummary($website->fresh()),
            'cloudflare' => $report['lines'] ?? [],
            'problems' => $report ? CloudflareRoutes::problems($report) : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'website' => $schema->string()->description('The website id, slug, or domain.')->required(),
            'environment' => $schema->string()->description('The environment id or name (list-site-environments).')->required(),
            'name' => $schema->string()->description('New environment name.'),
            'branch' => $schema->string()->description('The branch it deploys.'),
            'domain' => $schema->string()->description('Its main domain.'),
            'aliases' => $schema->array()->items($schema->string())->description('Its other domains (the complete list).'),
        ];
    }
}
