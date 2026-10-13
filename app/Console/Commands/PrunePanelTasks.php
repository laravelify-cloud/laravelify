<?php

namespace App\Console\Commands;

use App\Models\PanelTask;
use Illuminate\Console\Command;

class PrunePanelTasks extends Command
{
    /**
     * @var string
     */
    protected $signature = 'laravelify:prune-tasks';

    /**
     * @var string
     */
    protected $description = 'Delete finished background tasks and their files after a day';

    public function handle(): int
    {
        $cutoff = now()->subDay();
        $removed = 0;

        PanelTask::query()->where('updated_at', '<', $cutoff)->each(function (PanelTask $task) use (&$removed) {
            if ($file = $task->resultFile()) {
                @unlink($file);
            }

            $task->delete();
            $removed++;
        });

        foreach (glob(PanelTask::directory().DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff->getTimestamp()) {
                @unlink($file);
            }
        }

        $this->info($removed.' old task(s) removed.');

        return self::SUCCESS;
    }
}
