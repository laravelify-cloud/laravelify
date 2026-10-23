<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\CodeServer;
use Illuminate\Console\Command;
use Throwable;

class CodeServerCredentials extends Command
{
    protected $signature = 'laravelify:code-server-credentials';

    protected $description = 'Give the running VS Code editors a fresh GitHub token for git (tokens last an hour)';

    public function handle(CodeServer $editors): int
    {
        foreach (Website::query()->whereNotNull('code_server_token')->get() as $website) {
            try {
                $editors->refreshCredentials($website);
            } catch (Throwable $exception) {
                $this->warn($website->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
