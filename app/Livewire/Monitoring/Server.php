<?php

namespace App\Livewire\Monitoring;

use App\Livewire\PanelComponent;
use App\Services\ServerStats;
use App\Services\SiteMetrics;
use Livewire\Attributes\Url;

/**
 * CPU, memory, load, disk, network, services, and the busiest processes of this server.
 */
class Server extends PanelComponent
{
    #[Url(as: 'range', except: '24h')]
    public string $range = '24h';

    public function mount(): void
    {
        $this->range = SiteMetrics::range($this->range);
    }

    public function setRange(string $range): void
    {
        $this->range = SiteMetrics::range($range);
    }

    public function render(ServerStats $stats)
    {
        return view('livewire.monitoring.server', [
            'now' => $stats->snapshot(),
            'history' => $stats->history($this->range),
            'services' => $stats->services(),
        ])->layout('layouts.app')->title('Server monitoring');
    }
}
