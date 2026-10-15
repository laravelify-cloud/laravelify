<?php

namespace App\Mcp\Concerns;

use App\Models\Deployment;
use App\Models\Website;
use Illuminate\Validation\ValidationException;

/**
 * Finding a website by id, slug, or domain, and the short description the MCP tools return.
 */
trait DescribesWebsites
{
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
            'http_port' => $website->http_port,
            'repository' => $website->repository,
            'branch' => $website->branch,
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
