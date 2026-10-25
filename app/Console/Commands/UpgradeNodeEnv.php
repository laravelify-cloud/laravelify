<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\ConfigRenderer;
use App\Services\HostWriter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Node apps saved with env_file in their PM2 file never got their .env: PM2 ignores it. Move it
 * to LARAVELIFY_ENV_FILE, which with-node.sh loads, and apply the PM2 app again (it restarts).
 * Run by every update; a file already moved is left alone.
 */
class UpgradeNodeEnv extends Command
{
    protected $signature = 'laravelify:node-env';

    protected $description = 'Let Node apps read their .env (PM2 ignores env_file)';

    public function handle(ConfigRenderer $renderer, HostWriter $host): int
    {
        $failed = 0;

        foreach (Website::query()->where('process_config', 'like', '%env_file:%')->orderBy('slug')->get() as $website) {
            if (! $website->isNode()) {
                continue;
            }

            $config = $renderer->upgradeNodeEnv($website, (string) $website->process_config);

            if ($config === $website->process_config) {
                continue;
            }

            $previous = $website->process_config;
            $website->forceFill(['process_config' => $config])->save();

            if (! $website->enabled) {
                $this->line($website->slug.': .env is read from the next start.');

                continue;
            }

            try {
                $host->writeProcesses($website);
                $this->line($website->slug.': restarted, now with its .env.');
            } catch (Throwable $exception) {
                $website->forceFill(['process_config' => $previous])->save();
                $failed++;
                $this->warn($website->slug.': not changed, it keeps running as before. '.$exception->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
