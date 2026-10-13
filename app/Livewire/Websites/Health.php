<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Services\DisplayTime;
use App\Services\HostWriter;
use App\Services\ServerProcesses;
use App\Services\SiteHealth;
use Livewire\Attributes\Lazy;
use Throwable;

#[Lazy]
class Health extends PanelComponent
{
    public Website $website;

    /** @var list<array<string, mixed>> */
    public array $checks = [];

    public ?string $checkedAt = null;

    public function mount(Website $website): void
    {
        $this->website = $website;
        $this->runChecks();
    }

    public function placeholder(): string
    {
        return '<div class="layer-card"><div class="layer-card-head"><h2>Health</h2></div><div class="layer-card-body flex items-center gap-3 text-muted"><span class="loader-spinner" style="width:16px;height:16px"></span> Running checks…</div></div>';
    }

    public function runChecks(): void
    {
        $this->checks = app(SiteHealth::class)->check($this->website->fresh() ?? $this->website);
        $this->checkedAt = app(DisplayTime::class)->time(now());
    }

    public function restart(string $manager, string $program, ServerProcesses $processes): void
    {
        $allowed = collect($this->checks)->contains(fn (array $check): bool => ($check['program'] ?? null) === $program && ($check['manager'] ?? null) === $manager);

        if (! $allowed) {
            $this->toast('That process does not belong to this site.', 'error');

            return;
        }

        try {
            $this->toast(trim($processes->restart($manager, $program)));
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }

        $this->runChecks();
    }

    public function enableNginxLogs(ConfigRenderer $renderer, HostWriter $hostWriter): void
    {
        if ($renderer->addNginxLogs($this->website)) {
            $this->website->save();

            try {
                $hostWriter->writeWebsite($this->website);
                $this->toast('nginx now writes this site\'s access and error logs.');
            } catch (Throwable $exception) {
                $this->toast('Saved, but applying nginx failed: '.$exception->getMessage(), 'error');
            }
        }

        $this->runChecks();
    }

    public function render()
    {
        $statuses = collect($this->checks)->pluck('status');

        return view('livewire.websites.health', [
            'summary' => match (true) {
                $statuses->contains('fail') => ['fail', 'Something needs attention'],
                $statuses->contains('warn') => ['warn', 'Working, with warnings'],
                default => ['ok', 'Everything looks healthy'],
            },
        ]);
    }
}
