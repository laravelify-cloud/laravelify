<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Services\HostWriter;
use App\Services\SiteLogs;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Throwable;

class Logs extends PanelComponent
{
    public Website $website;

    /** The selected log, kept in the URL as ?log=. */
    #[Url(as: 'log', except: 'laravel')]
    public string $source = 'laravel';

    public int $lines = 500;

    /** Re-read the log every few seconds. */
    public bool $live = false;

    public string $content = '';

    public string $error = '';

    public function mount(Website $website): void
    {
        $this->website = $website;

        if (! $website->isLaravel() && $this->source === 'laravel') {
            $this->source = 'pm2';
        }

        $this->load();
    }

    #[On('open-log')]
    public function show(string $key): void
    {
        $this->source = $key;
        $this->load();
    }

    public function updatedSource(): void
    {
        $this->load();
    }

    public function updatedLines(): void
    {
        $this->lines = in_array($this->lines, [100, 500, 1000, 2000, 5000], true) ? $this->lines : 500;
        $this->load();
    }

    public function load(): void
    {
        $this->error = '';

        try {
            $this->content = app(SiteLogs::class)->tail($this->website, $this->source, $this->lines);
        } catch (Throwable $exception) {
            $this->content = '';
            $this->error = $exception->getMessage();
        }
    }

    public function clearLog(SiteLogs $logs): void
    {
        if (! $this->confirmed('clear')) {
            return;
        }

        try {
            $logs->truncate($this->website, $this->source);
            $this->load();
            $this->dispatch('log-cleared');
            $this->toast('Log cleared.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    /**
     * Add per-site access_log and error_log lines to the nginx config and apply it.
     */
    public function enableNginxLogs(ConfigRenderer $renderer, HostWriter $hostWriter): void
    {
        if (! $renderer->addNginxLogs($this->website)) {
            $this->toast('This nginx config already has site logs, or it has no server_name line.', 'warning');

            return;
        }

        $this->website->save();

        try {
            $hostWriter->writeWebsite($this->website);
            $this->load();
            $this->toast('nginx now writes this site\'s access and error logs.');
        } catch (Throwable $exception) {
            $this->toast('Saved, but applying nginx failed: '.$exception->getMessage(), 'error');
        }
    }

    public function render(SiteLogs $logs, ConfigRenderer $renderer)
    {
        $sources = $logs->sources($this->website);
        $current = collect($sources)->firstWhere('key', $this->source);

        return view('livewire.websites.logs', [
            'sources' => $sources,
            'current' => $current,
            'nginxReady' => $renderer->hasNginxLogs($this->website),
            'canClear' => $current !== null && ! str_starts_with($this->source, 'nginx') && $this->source !== 'pm2',
            'logLines' => $this->content === '' ? [] : (preg_split('/\R/', $this->content) ?: []),
        ]);
    }
}
