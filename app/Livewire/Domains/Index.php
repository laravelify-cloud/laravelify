<?php

namespace App\Livewire\Domains;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\LiveServer;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Every domain and subdomain the panel answers on, and what each one is: the panel itself, a
 * website's domain or alias, a preview, or a VS Code Live Server (running or stopped, with Stop
 * and Release here).
 */
class Index extends PanelComponent
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'kind', except: '')]
    public string $kind = '';

    public function stopLive(int $websiteId, LiveServer $live): void
    {
        $website = Website::query()->findOrFail($websiteId);

        try {
            $live->stop($website);
            $this->toast('Live Server of '.$website->name.' stopped. It keeps '.$website->live_host.' for its next Go Live.');
        } catch (Throwable $exception) {
            $this->toast('Could not stop the Live Server: '.$exception->getMessage(), 'error');
        }
    }

    public function releaseLive(int $websiteId, LiveServer $live): void
    {
        $website = Website::query()->findOrFail($websiteId);
        $host = $website->live_host;

        try {
            $live->release($website);
            $this->toast($host.' is released: its tunnel route and DNS record are removed.');
        } catch (Throwable $exception) {
            $this->toast('Could not release '.$host.': '.$exception->getMessage(), 'error');
        }
    }

    /**
     * @return list<array{host: string, kind: string, label: string, website: ?Website, running: ?bool}>
     */
    private function rows(LiveServer $live): array
    {
        $rows = [];
        $panelHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($panelHost !== '') {
            $rows[] = ['host' => $panelHost, 'kind' => 'panel', 'label' => 'Panel', 'website' => null, 'running' => null];
        }

        foreach (Website::query()->with('parent')->orderBy('name')->get() as $website) {
            $env = $website->parent_id ? ' ('.ucfirst((string) ($website->environment ?: 'environment')).')' : '';

            if ($website->domain) {
                $rows[] = ['host' => strtolower((string) $website->domain), 'kind' => 'website', 'label' => 'Website'.$env, 'website' => $website, 'running' => null];
            }

            foreach ((array) $website->aliases as $alias) {
                $rows[] = ['host' => strtolower((string) $alias), 'kind' => 'website', 'label' => 'Other domain'.$env, 'website' => $website, 'running' => null];
            }

            if ($website->preview_host) {
                $rows[] = ['host' => (string) $website->preview_host, 'kind' => 'preview', 'label' => 'Preview', 'website' => $website, 'running' => null];
            }

            if ($website->live_host) {
                $rows[] = ['host' => (string) $website->live_host, 'kind' => 'live', 'label' => 'Live Server', 'website' => $website, 'running' => $live->running($website)];
            }
        }

        $search = strtolower(trim($this->search));

        return array_values(array_filter($rows, fn (array $row) => ($this->kind === '' || $row['kind'] === $this->kind)
            && ($search === '' || str_contains($row['host'], $search) || str_contains(strtolower((string) $row['website']?->name), $search))));
    }

    public function render(LiveServer $live)
    {
        return view('livewire.domains.index', ['rows' => $this->rows($live)])
            ->layout('layouts.app')
            ->title('Domains');
    }
}
