<?php

namespace App\Livewire;

use App\Models\CloudflareTunnel;
use App\Models\Deployment;
use App\Models\Group;
use App\Models\ServerSample;
use App\Models\Website;
use Illuminate\Contracts\View\View;

class Dashboard extends PanelComponent
{
    public function render(): View
    {
        $groupCount = Group::query()->count();
        $websiteCount = Website::query()->count();
        $tunnelCount = CloudflareTunnel::query()->count();

        return view('livewire.dashboard', [
            'groupCount' => $groupCount,
            'websiteCount' => $websiteCount,
            'tunnelCount' => $tunnelCount,
            'liveWebsiteCount' => Website::query()->where('enabled', true)->count(),
            'week' => $this->deploymentWeek(),
            'server' => ServerSample::query()->where('at', '>=', now()->subMinutes(15))->latest('at')->first(),
            'onboarding' => $this->onboardingSteps($groupCount, $websiteCount, $tunnelCount),
            'firstGroup' => Group::query()->oldest('id')->first(),
            'deployments' => Deployment::query()->with('website')->latest()->limit(8)->get(),
        ])->layout('layouts.app')->title('Dashboard');
    }

    /**
     * Deploys of the last seven days: how many ran, how many finished well, and how many failed.
     *
     * @return array{total: int, success: int, failed: int, rate: int|null}
     */
    protected function deploymentWeek(): array
    {
        $counts = Deployment::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $success = (int) ($counts['success'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0);

        return [
            'total' => (int) $counts->sum(),
            'success' => $success,
            'failed' => $failed,
            'rate' => $success + $failed > 0 ? (int) round($success / ($success + $failed) * 100) : null,
        ];
    }

    /**
     * The first steps of a new panel, in order. Empty once every step is done.
     *
     * @return list<array{title: string, text: string, done: bool}>
     */
    protected function onboardingSteps(int $groupCount, int $websiteCount, int $tunnelCount): array
    {
        $steps = [
            ['title' => 'Create a group', 'text' => 'A group holds the websites of one product or client.', 'done' => $groupCount > 0],
            ['title' => 'Add a website', 'text' => 'Pick a repository and a framework; the panel sets up the rest.', 'done' => $websiteCount > 0],
            ['title' => 'Deploy it', 'text' => 'The first build runs, then every push can deploy on its own.', 'done' => Deployment::query()->where('status', 'success')->exists()],
            ['title' => 'Put it online', 'text' => 'A Cloudflare tunnel serves your domains without opening ports.', 'done' => $tunnelCount > 0],
        ];

        return collect($steps)->every(fn (array $step): bool => $step['done']) ? [] : $steps;
    }
}
