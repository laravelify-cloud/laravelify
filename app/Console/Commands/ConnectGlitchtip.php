<?php

namespace App\Console\Commands;

use App\Services\Glitchtip;
use Illuminate\Console\Command;

/**
 * Give every website without a GlitchTip project one, on servers where websites were added before
 * new ones were connected by themselves. Run by every update; nothing happens without GlitchTip.
 */
class ConnectGlitchtip extends Command
{
    protected $signature = 'laravelify:glitchtip-connect';

    protected $description = 'Connect every website to GlitchTip error tracking';

    public function handle(Glitchtip $glitchtip): int
    {
        if (! $glitchtip->installed()) {
            return self::SUCCESS;
        }

        $result = $glitchtip->connectAll();
        $this->line('GlitchTip: '.$result['connected'].' '.str('website')->plural($result['connected']).' connected.');

        foreach ($result['failed'] as $name => $reason) {
            $this->warn('GlitchTip: could not connect '.$name.': '.$reason);
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
