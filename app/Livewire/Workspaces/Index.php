<?php

namespace App\Livewire\Workspaces;

use App\Livewire\PanelComponent;
use App\Models\CodeWorkspace;
use App\Models\Website;
use App\Services\CodeServer;
use App\Services\CodeWorkspaces;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Throwable;

/**
 * VS Code workspaces: every project opened in VS Code (one per website, added when its editor
 * opens) and workspaces of several projects in one editor. Each can be opened, downloaded as a
 * zip, shared with a link anyone can open, and deleted (the projects stay).
 */
class Index extends PanelComponent
{
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $creating = false;

    /** @var list<int> */
    public array $selected = [];

    public string $name = '';

    public string $projectSearch = '';

    /** The workspace whose share dialog is open. */
    public ?int $sharing = null;

    public function startCreate(): void
    {
        $this->resetErrorBag();
        $this->creating = true;
        $this->selected = [];
        $this->name = '';
        $this->projectSearch = '';
    }

    public function closeCreate(): void
    {
        $this->creating = false;
    }

    /** The name a workspace of the picked projects gets when none is typed. */
    public function suggestedName(CodeWorkspaces $workspaces): string
    {
        $websites = Website::query()->whereKey($this->selected)->orderBy('name')->get();

        return match ($websites->count()) {
            0 => '',
            1 => (string) $websites->first()->name,
            default => $workspaces->nameFor($websites),
        };
    }

    public function create(CodeWorkspaces $workspaces): void
    {
        $this->validate([
            'selected' => ['array', 'min:1'],
            'selected.*' => ['integer', 'exists:websites,id'],
            'name' => ['nullable', 'string', 'max:80'],
        ], [
            'selected.min' => 'Pick a project.',
        ]);

        try {
            $workspace = $workspaces->create(array_map('intval', $this->selected), Auth::user(), $this->name);
        } catch (Throwable $exception) {
            $this->addError('selected', $exception->getMessage());

            return;
        }

        $this->creating = false;
        $this->toast('Workspace '.$workspace->name.' created.');
    }

    public function openShare(int $id): void
    {
        $this->sharing = CodeWorkspace::query()->findOrFail($id)->id;
    }

    public function closeShare(): void
    {
        $this->sharing = null;
    }

    public function share(int $id, CodeWorkspaces $workspaces): void
    {
        $workspaces->share(CodeWorkspace::query()->findOrFail($id));
        $this->toast('Share link turned on. Anyone with it can open and change this workspace.');
    }

    public function unshare(int $id, CodeWorkspaces $workspaces): void
    {
        $workspaces->unshare(CodeWorkspace::query()->findOrFail($id));
        $this->toast('Share link turned off. It no longer opens the workspace.');
    }

    public function delete(int $id, CodeWorkspaces $workspaces): void
    {
        $workspace = CodeWorkspace::query()->with('websites')->findOrFail($id);

        try {
            $workspaces->delete($workspace);
            $this->toast('Workspace '.$workspace->name.' deleted. Its projects are untouched.');
        } catch (Throwable $exception) {
            $this->toast('Could not delete the workspace: '.$exception->getMessage(), 'error');
        }
    }

    public function render(CodeServer $editors, CodeWorkspaces $workspaces)
    {
        $search = strtolower(trim($this->search));
        $rows = CodeWorkspace::query()->with('websites')->latest('opened_at')->latest('id')->get()
            ->filter(fn (CodeWorkspace $workspace) => $search === ''
                || str_contains(strtolower($workspace->name), $search)
                || $workspace->websites->contains(fn (Website $website) => str_contains(strtolower($website->name), $search)))
            ->values();

        // Only projects VS Code can open (GitHub or example repositories, with their own user).
        $projectSearch = strtolower(trim($this->projectSearch));
        $projects = $this->creating
            ? Website::query()->orderBy('name')->get()
                ->filter(fn (Website $website) => $editors->unavailableReason($website) === null
                    && ($projectSearch === '' || str_contains(strtolower($website->name), $projectSearch)))
                ->values()
            : collect();

        $shared = $this->sharing ? $rows->firstWhere('id', $this->sharing) : null;

        return view('livewire.workspaces.index', [
            'rows' => $rows,
            'projects' => $projects,
            'suggested' => $this->creating ? $this->suggestedName($workspaces) : '',
            'shared' => $shared,
            'shareUrl' => $shared ? $workspaces->shareUrl($shared) : null,
        ])->layout('layouts.app')->title('Workspaces');
    }
}
