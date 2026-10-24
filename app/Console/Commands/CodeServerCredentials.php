<?php

namespace App\Console\Commands;

use App\Models\CodeWorkspace;
use App\Models\Website;
use App\Services\CodeServer;
use App\Services\CodeWorkspaces;
use Illuminate\Console\Command;
use Throwable;

class CodeServerCredentials extends Command
{
    protected $signature = 'laravelify:code-server-credentials';

    protected $description = 'Give the running VS Code editors a fresh GitHub token for git (tokens last an hour)';

    public function handle(CodeServer $editors, CodeWorkspaces $workspaces): int
    {
        foreach (Website::query()->whereNotNull('code_server_token')->get() as $website) {
            try {
                $editors->refreshCredentials($website);
            } catch (Throwable $exception) {
                $this->warn($website->name.': '.$exception->getMessage());
            }
        }

        // Workspaces with several projects have an editor of their own.
        foreach (CodeWorkspace::query()->whereNotNull('code_server_token')->with('websites.githubApp')->get() as $workspace) {
            try {
                $workspaces->refreshCredentials($workspace);
            } catch (Throwable $exception) {
                $this->warn($workspace->name.': '.$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
