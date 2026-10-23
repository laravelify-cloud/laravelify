<?php

namespace App\Livewire\Websites;

use App\Livewire\PanelComponent;
use App\Models\Website;
use App\Services\CodeServer;
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

    public function mount(Website $website, CodeServer $editors): void
    {
        $this->website = $website;
        $this->error = $editors->unavailableReason($website);
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
