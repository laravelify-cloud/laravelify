<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\PanelTask;
use App\Models\Website;
use App\Services\CodeServer;
use App\Services\PanelTaskRunner;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Opens VS Code (code-server) for a website: installs and starts it when needed, then sends the
 * browser to it. The first start downloads code-server (about 100 MB) and clones the repository.
 */
class VsCode extends PanelComponent
{
    public Website $website;

    public ?string $error = null;

    /** MB of code-server downloaded so far, while it installs the first time. */
    public ?int $installing = null;

    /** The size of the download in MB (0 while unknown). */
    public int $total = 0;

    /** The website still runs as the shared user, and the launcher can move it. */
    public bool $canMove = false;

    /** The background task moving the website to its own user, while it runs. */
    public ?int $moveTask = null;

    public ?string $moveStatus = null;

    public function mount(Website $website, CodeServer $editors): void
    {
        $this->website = $website;
        $this->error = $editors->unavailableReason($website);
        $this->canMove = $editors->canMoveToOwnUser($website);

        // A move started before a reload is still running: follow it.
        $running = PanelTask::query()->where('scope', $this->moveScope())->whereIn('status', [PanelTask::QUEUED, PanelTask::RUNNING])->latest('id')->first();

        if ($running) {
            $this->moveTask = $running->id;
            $this->moveStatus = $running->message ?: 'Moving the website to its own user…';
        }
    }

    /**
     * Move the website to its own user in the background (as laravelify:separate-sites does: a
     * site that stops answering goes back to the shared user), then open VS Code. A Node app
     * restarting under PM2 can take longer than a page request may last.
     */
    public function moveToOwnUser(CodeServer $editors, PanelTaskRunner $tasks): void
    {
        if ($this->moveTask !== null || ! $editors->canMoveToOwnUser($this->website)) {
            return;
        }

        $this->moveTask = $tasks->start('websites.own-user', $this->moveScope(), 'Moving '.$this->website->name.' to its own user', ['website_id' => $this->website->id])->id;
        $this->moveStatus = 'Moving the website to its own user…';
    }

    /** Polled while the move runs. */
    public function checkMove(CodeServer $editors): void
    {
        $task = $this->moveTask ? PanelTask::query()->find($this->moveTask) : null;

        if ($task === null) {
            $this->moveTask = null;

            return;
        }

        if (in_array($task->status, [PanelTask::QUEUED, PanelTask::RUNNING], true)) {
            $this->moveStatus = $task->status === PanelTask::QUEUED ? 'Waiting for the panel\'s background worker…' : ($task->message ?: 'Moving the website to its own user…');

            return;
        }

        $this->moveTask = null;
        $this->moveStatus = null;
        $this->canMove = $editors->canMoveToOwnUser($this->website);

        if ($task->status === PanelTask::FAILED) {
            $this->error = 'Could not move the website to its own user: '.($task->message ?: 'see the panel log.');

            return;
        }

        $this->retry($editors);
    }

    private function moveScope(): string
    {
        return 'websites:own-user:'.$this->website->id;
    }

    public function launch(CodeServer $editors): void
    {
        if ($this->error !== null) {
            return;
        }

        try {
            $result = $editors->start($this->website, (string) Auth::user()?->name);
        } catch (Throwable $exception) {
            $this->installing = null;
            $this->error = $exception->getMessage();

            return;
        }

        // Still downloading code-server the first time: the page asks again in a few seconds.
        if ($result['url'] === null) {
            $this->installing = $result['installing'];
            $this->total = $result['total'] ?? 0;

            return;
        }

        // A full page load: VS Code is not a Livewire page.
        $this->redirect($result['url']);
    }

    public function retry(CodeServer $editors): void
    {
        $this->error = $editors->unavailableReason($this->website);
        $this->launch($editors);
    }

    public function render()
    {
        return view('livewire.websites.vs-code')->layout('layouts.editor')->title($this->website->name.' · VS Code');
    }
}
