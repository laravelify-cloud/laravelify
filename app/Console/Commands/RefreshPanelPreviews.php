<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\PanelPreview;
use Illuminate\Console\Command;
use Throwable;

class RefreshPanelPreviews extends Command
{
    protected $signature = 'laravelify:refresh-previews';

    protected $description = 'Write the nginx location of every website preview again (after an update changed how they are built)';

    public function handle(PanelPreview $previews): int
    {
        foreach (Website::query()->whereNotNull('preview_token')->get() as $website) {
            try {
                $previews->enable($website);
                $this->line('Preview of '.$website->name.' refreshed.');
            } catch (Throwable $exception) {
                $this->warn('Preview of '.$website->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
