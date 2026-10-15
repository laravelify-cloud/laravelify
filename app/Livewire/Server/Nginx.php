<?php

namespace App\Livewire\Server;

use App\Livewire\PanelComponent;
use App\Services\HostWriter;
use App\Services\NginxOverview;
use Throwable;

class Nginx extends PanelComponent
{
    /** @var array{ok: bool, output: string, at: string}|null */
    public ?array $test = null;

    public ?string $log = null;

    public int $lines = 200;

    public function runTest(HostWriter $host): void
    {
        try {
            $output = $host->nginxTest();
            $this->test = ['ok' => true, 'output' => trim($output), 'at' => now()->toDateTimeString()];
            $this->toast(str_starts_with($output, '[skipped]') ? 'Host commands are off here, so nginx was not tested.' : 'nginx accepts every config.');
        } catch (Throwable $exception) {
            $this->test = ['ok' => false, 'output' => trim($exception->getMessage()), 'at' => now()->toDateTimeString()];
            $this->toast('nginx rejects its config. The message is below.', 'error');
        }
    }

    public function loadLog(HostWriter $host): void
    {
        $this->lines = max(20, min($this->lines, 2000));

        try {
            $this->log = trim($host->nginxErrorLog($this->lines));
        } catch (Throwable $exception) {
            $this->log = 'Could not read the log: '.$exception->getMessage();
        }
    }

    public function render(NginxOverview $overview)
    {
        $files = $overview->files();
        $servers = $overview->servers($files);

        return view('livewire.server.nginx', [
            'files' => $files,
            'servers' => $servers,
            'ports' => NginxOverview::ports($servers),
            'conflicts' => NginxOverview::conflicts($servers),
        ])->layout('layouts.app')->title('Nginx');
    }
}
