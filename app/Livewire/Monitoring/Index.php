<?php

namespace App\Livewire\Monitoring;

use App\Livewire\PanelComponent;
use App\Models\SiteIssue;
use App\Models\Website;
use App\Services\SiteMetrics;

/**
 * Every website at a glance: up or down, traffic, errors, speed, and threats
 * over the last 24 hours.
 */
class Index extends PanelComponent
{
    public function render(SiteMetrics $metrics)
    {
        $websites = Website::query()->with('group')->orderBy('name')->get();
        $overview = $metrics->overview($websites);
        $openIssues = SiteIssue::query()
            ->where('status', 'open')
            ->selectRaw('website_id, count(*) as total')
            ->groupBy('website_id')
            ->pluck('total', 'website_id');

        return view('livewire.monitoring.index', [
            'websites' => $websites,
            'overview' => $overview,
            'openIssues' => $openIssues,
            'totals' => [
                'up' => collect($overview)->where('up', true)->count(),
                'checked' => collect($overview)->whereNotNull('up')->count(),
                'requests' => collect($overview)->sum('requests'),
                'server_errors' => collect($overview)->sum('server_errors'),
                'issues' => (int) $openIssues->sum(),
                'threats' => collect($overview)->sum('threats'),
            ],
        ])->layout('layouts.app')->title('Monitoring');
    }
}
