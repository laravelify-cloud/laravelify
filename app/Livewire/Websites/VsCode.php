<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\CodeServer;
use Illuminate\Support\Facades\Artisan;
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

    public function mount(Website $website, CodeServer $editors): void
    {
        $this->website = $website;
        $this->error = $editors->unavailableReason($website);
        $this->canMove = $editors->canMoveToOwnUser($website);
    }

    /**
     * Move the website to its own user (as laravelify:separate-sites does: a site that stops
     * answering goes back to the shared user), then open VS Code.
     */
    public function moveToOwnUser(CodeServer $editors): void
    {
        if (! $editors->canMoveToOwnUser($this->website)) {
            return;
        }

        $exit = Artisan::call('laravelify:separate-sites', ['--site' => [$this->website->slug]]);
        $this->canMove = $editors->canMoveToOwnUser($this->website);

        if ($exit !== 0 || $this->canMove) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));
            $this->error = 'Could not move the website to its own user: '.(end($lines) ?: 'see the panel log.');

            return;
        }

        $this->retry($editors);
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
