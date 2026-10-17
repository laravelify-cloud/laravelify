<?php

namespace App\Livewire\Deployments;

use App\Livewire\PanelComponent;
use App\Models\Deployment;
use App\Models\Website;
use Livewire\Attributes\Url;

/**
 * Every website as a card with the state of its latest deployment, for a glance at what is
 * building, what failed, and what is live. A card opens the website's deployment log.
 */
class Index extends PanelComponent
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'show', except: 'all')]
    public string $filter = 'all';

    public function deploy(int $websiteId): void
    {
        $website = Website::query()->findOrFail($websiteId);

        if (! $website->enabled) {
            $this->toast($website->name.' is turned off. Turn it on in its Danger zone to deploy.', 'error');

            return;
        }

        if (! $website->queueDeployment('manual')) {
            $this->toast('A deployment of '.$website->name.' is already running.', 'error');

            return;
        }

        $this->toast('Deployment of '.$website->name.' queued.');
    }

    public function render()
    {
        $websites = Website::query()
            ->with('group')
            ->when(trim($this->search) !== '', fn ($query) => $query->where('name', 'like', '%'.trim($this->search).'%'))
            ->orderBy('name')
            ->get();

        $latestIds = Deployment::query()
            ->whereIn('website_id', $websites->modelKeys())
            ->selectRaw('max(id) as id')
            ->groupBy('website_id')
            ->pluck('id');
        $latest = Deployment::query()->whereIn('id', $latestIds)->get(['id', 'website_id', 'status', 'trigger', 'commit_sha', 'commit_message', 'commit_author', 'branch', 'started_at', 'finished_at', 'created_at'])->keyBy('website_id');

        $cards = $websites
            ->map(fn (Website $website): array => ['website' => $website, 'deployment' => $latest->get($website->id)])
            ->filter(fn (array $card): bool => match ($this->filter) {
                'running' => in_array($card['deployment']?->status, ['queued', 'running'], true),
                'failed' => $card['deployment']?->status === 'failed',
                'success' => $card['deployment']?->status === 'success',
                default => true,
            })
            ->values();

        return view('livewire.deployments.index', [
            'cards' => $cards,
            'running' => $latest->filter(fn (Deployment $deployment): bool => in_array($deployment->status, ['queued', 'running'], true))->count(),
        ])->layout('layouts.app')->title('Deployments');
    }
}
