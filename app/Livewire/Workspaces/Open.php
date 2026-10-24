<?php

namespace App\Livewire\Workspaces;

use App\Livewire\PanelComponent;
use App\Models\CodeWorkspace;
use App\Models\PanelTask;
use App\Services\CodeWorkspaces;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Opens a workspace's VS Code: starts it when needed (cloning or updating each project), then
 * sends the browser to it. A workspace of one project opens that website's own editor.
 */
class Open extends PanelComponent
{
    public CodeWorkspace $workspace;

    public ?string $error = null;

    /** MB of code-server downloaded so far, while it installs the first time. */
    public ?int $installing = null;

    /** The size of the download in MB (0 while unknown). */
    public int $total = 0;

    /** @var list<int> Background tasks moving projects to their own users, while they run. */
    public array $moveTasks = [];

    public ?string $moveStatus = null;

    public function mount(CodeWorkspace $workspace, CodeWorkspaces $workspaces): void
    {
        $this->workspace = $workspace->load('websites');

        // Projects still on the shared user move to their own users first (or a move under way is followed).
        $this->moveTasks = $workspaces->moveToOwnUsers($this->workspace);
        $this->moveStatus = $this->moveTasks ? 'Moving '.count($this->moveTasks).' '.str('project')->plural(count($this->moveTasks)).' to '.(count($this->moveTasks) > 1 ? 'their' : 'its').' own user…' : null;
    }

    /** Polled while projects move. */
    public function checkMoves(CodeWorkspaces $workspaces): void
    {
        $tasks = PanelTask::query()->whereKey($this->moveTasks)->get();
        $waiting = $tasks->whereIn('status', [PanelTask::QUEUED, PanelTask::RUNNING]);

        if ($waiting->isNotEmpty()) {
            $first = $waiting->first();
            $this->moveStatus = $first->status === PanelTask::QUEUED ? 'Waiting for the panel\'s background worker…' : ($first->message ?: $first->label.'…');

            return;
        }

        $this->moveTasks = [];
        $this->moveStatus = null;

        if ($failed = $tasks->firstWhere('status', PanelTask::FAILED)) {
            $this->error = 'Could not move a project to its own user: '.($failed->message ?: 'see the panel log.');

            return;
        }

        $this->workspace->load('websites');
        $this->launch($workspaces);
    }

    /** Another launch of this workspace is still under way (another tab, an earlier click). */
    public bool $starting = false;

    public function launch(CodeWorkspaces $workspaces): void
    {
        if ($this->moveTasks) {
            return;
        }

        // One launch at a time: a second one waits and asks again, instead of preparing the
        // projects a second time while the first is cloning them.
        $lock = Cache::lock('workspace-launch:'.$this->workspace->id, 900);

        if (! $lock->get()) {
            $this->starting = true;

            return;
        }

        $this->starting = false;

        try {
            $this->launchNow($workspaces);
        } finally {
            $lock->release();
        }
    }

    private function launchNow(CodeWorkspaces $workspaces): void
    {
        try {
            $result = $workspaces->start($this->workspace, (string) Auth::user()?->name);
        } catch (Throwable $exception) {
            $this->installing = null;
            $this->error = $exception->getMessage();

            return;
        }

        if ($result['url'] === null) {
            $this->installing = $result['installing'];
            $this->total = $result['total'] ?? 0;

            return;
        }

        $this->redirect($result['url']);
    }

    public function retry(CodeWorkspaces $workspaces): void
    {
        $this->error = null;
        $this->workspace->load('websites');
        $this->moveTasks = $workspaces->moveToOwnUsers($this->workspace);
        $this->moveStatus = $this->moveTasks ? 'Moving to its own user…' : null;
        $this->launch($workspaces);
    }

    public function render()
    {
        return view('livewire.workspaces.open')->layout('layouts.editor')->title($this->workspace->name.' · VS Code');
    }
}
