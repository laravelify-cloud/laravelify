<?php

namespace App\Console\Commands;

use App\Services\ServerStats;
use Illuminate\Console\Command;

class SampleServer extends Command
{
    /**
     * @var string
     */
    protected $signature = 'laravelify:sample-server';

    /**
     * @var string
     */
    protected $description = 'Store one CPU, memory, load, disk, and network sample for Server monitoring';

    public function handle(ServerStats $stats): int
    {
        $sample = $stats->sample();

        $this->line($sample === null
            ? 'No /proc on this machine, nothing to sample.'
            : 'cpu '.($sample->cpu ?? '-').'%, memory '.($sample->memory ?? '-').'%, load '.($sample->load1 ?? '-'));

        return self::SUCCESS;
    }
}
