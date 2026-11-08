<?php

namespace App\Mcp\Concerns;

use App\Models\Deployment;
use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Finding a website by id, slug, or domain, and the short description the MCP tools return.
 */
trait DescribesWebsites
{
    /**
     * The website a per-site tool works on: the one given, or with `environment` one of its
     * environments (by id or name; "production" is the main website).
     */
    protected function findSite(Request $request): Website
    {
        $website = $this->findWebsite($request->get('website'));
        $environment = trim((string) $request->get('environment', ''));

        return $environment === '' ? $website : $this->findEnvironment($website, $environment);
    }

    protected function findEnvironment(Website $website, string $reference): Website
    {
        $main = $website->parent ?? $website;
        $name = strtolower(trim($reference));

        if ($name === '' || in_array($name, ['production', 'prod', 'main'], true) || $name === (string) $main->id) {
            return $main;
        }

        return $main->environments()
            ->where(fn ($query) => ctype_digit($name) ? $query->whereKey((int) $name) : $query->where('environment', $name))
            ->first()
            ?? throw ValidationException::withMessages(['environment' => $main->name.' has no "'.$reference.'" environment. list-site-environments shows them.']);
    }

    /**
     * The optional `environment` parameter of the per-site tools.
     */
    protected function environmentSchema(JsonSchema $schema): mixed
    {
        return $schema->string()->description('An environment of the website, by id or name like development (list-site-environments). Default: production, the main website.');
    }

    protected function findWebsite(mixed $reference): Website
    {
        $reference = trim((string) $reference);

        if ($reference === '') {
            throw ValidationException::withMessages(['website' => 'Give the website: its id, slug, or domain. list-websites shows them.']);
        }

        $website = Website::query()
            ->when(ctype_digit($reference), fn ($query) => $query->whereKey((int) $reference))
            ->unless(ctype_digit($reference), fn ($query) => $query->where('slug', $reference)->orWhere('domain', strtolower($reference)))
            ->first();

        // An extra domain (like www.example.com) finds its website too.
        $website ??= ctype_digit($reference) ? null : Website::query()->whereNotNull('aliases')->get()->first(fn (Website $candidate): bool => in_array(strtolower($reference), $candidate->domains(), true));

        return $website ?? throw ValidationException::withMessages(['website' => 'No website "'.$reference.'". list-websites shows the ids, slugs, and domains.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function websiteSummary(Website $website): array
    {
        $last = $website->deployments()->latest('id')->first();

        return [
            'id' => $website->id,
            'name' => $website->name,
            'slug' => $website->slug,
            'type' => $website->type,
            'group' => $website->group?->name,
            'url' => $website->publicUrl(),
            'domain' => $website->domain,
            'extra_domains' => $website->aliases ?? [],
            'cloudflare_tunnel_id' => $website->cloudflare_tunnel_id,
            'http_port' => $website->http_port,
            'repository' => $website->repository,
            'branch' => $website->branch,
            'environment' => $website->environmentName(),
            'environment_of' => $website->parent?->slug,
            'enabled' => (bool) $website->enabled,
            'auto_deploy' => (bool) $website->auto_deploy,
            'deploys_on_push_via' => match (true) {
                (bool) $website->github_app_id => 'GitHub App',
                preg_match('#^(https?://|git@)#', (string) $website->repository) === 1 => 'repository webhook (add it in GitHub)',
                default => 'nothing: not a GitHub repository, deploy by hand',
            },
            'last_deployment' => $last ? $this->deploymentSummary($last) : null,
            'panel_url' => route('websites.show', $website),
        ];
    }

    /**
     * One environment for list-site-environments: production is the main website.
     *
     * @return array<string, mixed>
     */
    protected function environmentSummary(Website $website): array
    {
        $last = $website->deployments()->latest('id')->first();

        return [
            'id' => $website->id,
            'name' => $website->environmentName(),
            'primary' => $website->parent_id === null,
            'slug' => $website->slug,
            'branch' => $website->branch,
            'domains' => $website->domains(),
            'url' => $website->publicUrl(),
            'http_port' => $website->http_port,
            'status' => ! $website->enabled ? 'turned off' : ($last?->status ?? 'never deployed'),
            'last_deployment' => $last ? $this->deploymentSummary($last) : null,
            'database' => $website->provision_database ? $website->db_name : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deploymentSummary(Deployment $deployment): array
    {
        return [
            'id' => $deployment->id,
            'status' => $deployment->status,
            'trigger' => $deployment->trigger,
            'commit' => $deployment->commit_sha ? substr($deployment->commit_sha, 0, 7) : null,
            'commit_message' => $deployment->commit_message ? mb_substr($deployment->commit_message, 0, 120) : null,
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'duration' => $deployment->durationLabel(),
        ];
    }
}
