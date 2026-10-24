<?php

namespace App\Livewire\Workspaces;

use App\Livewire\PanelComponent;
use App\Models\CodeWorkspace;
use App\Services\CodeWorkspaces;
use Illuminate\Support\Facades\Auth;
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

    public function mount(CodeWorkspace $workspace): void
    {
        $this->workspace = $workspace->load('websites');
    }

    public function launch(CodeWorkspaces $workspaces): void
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
        $this->launch($workspaces);
    }

    public function render()
    {
        return view('livewire.workspaces.open')->layout('layouts.editor')->title($this->workspace->name.' · VS Code');
    }
}
