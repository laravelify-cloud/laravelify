<?php

namespace App\Console\Commands;

use App\Services\HostWriter;
use App\Services\PanelWorker;
use App\Services\Telegram;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every minute (the scheduler is its own Supervisor program): when the panel's queue worker has
 * not beaten for two minutes, start it again and say so once on Telegram; say so again when
 * it is back. Deploys and background tasks wait while it is down.
 */
class WatchPanelWorker extends Command
{
    protected $signature = 'laravelify:watch-worker';

    protected $description = 'Start the panel queue worker again when it stopped, and report it';

    public function handle(PanelWorker $worker, HostWriter $host, Telegram $telegram): int
    {
        $panel = rtrim((string) config('app.url'), '/');
        $buttons = Telegram::buttonUrl($panel) ? [['text' => 'Open the panel', 'url' => $panel]] : [];

        if ($worker->running()) {
            if ($worker->markUp()) {
                $telegram->notify('process_recovered', '✅ <b>Panel worker running again</b>'."\nDeploys and background tasks go on.", $buttons);
            }

            return self::SUCCESS;
        }

        if ($worker->markDown()) {
            $telegram->notify('process_crashed', '⚠️ <b>Panel worker stopped</b>'."\nDeploys and background tasks wait until it runs. Laravelify is starting it again; if this stays, run <code>supervisorctl start laravelify-queue</code> on the server.", $buttons);
        }

        try {
            $this->line(trim($host->startPanelWorker()));
        } catch (Throwable $exception) {
            $this->warn('Could not start the panel worker: '.$exception->getMessage());
        }

        return self::SUCCESS;
    }
}
