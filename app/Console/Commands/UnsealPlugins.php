<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Services\PluginManager;
use Illuminate\Console\Command;
use Throwable;

class UnsealPlugins extends Command
{
    /**
     * @var string
     */
    protected $signature = 'laravelify:unseal-plugins';

    /**
     * @var string
     */
    protected $description = 'Unseal Vault plugins that have auto-unseal on and came back sealed (after a restart or reboot)';

    public function handle(PluginManager $plugins): int
    {
        $vaults = Plugin::query()->where('auto_unseal', true)->where('status', Plugin::RUNNING)->get()
            ->filter(fn (Plugin $plugin) => $plugin->definition()->sealable());

        foreach ($vaults as $plugin) {
            try {
                if ($plugins->refresh($plugin)->sealed) {
                    $plugins->unseal($plugin);
                    $this->info($plugin->definition()->label().' unsealed.');
                }
            } catch (Throwable $exception) {
                $this->warn($plugins->redact($plugin, $exception->getMessage()));
            }
        }

        return self::SUCCESS;
    }
}
