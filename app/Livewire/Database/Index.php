<?php

namespace App\Livewire\Database;

use App\Livewire\Concerns\ManagesDatabaseChanges;
use App\Livewire\PanelComponent;
use App\Models\DatabaseChange;
use App\Services\BackupManager;
use App\Services\DatabaseBrowser;
use Livewire\Attributes\Url;
use Throwable;

/**
 * Tools → Database: every database with its size, a search through all of them (data, table
 * names, column names), and the log of changes made here.
 */
class Index extends PanelComponent
{
    use ManagesDatabaseChanges;

    #[Url]
    public string $tab = 'databases';

    #[Url]
    public string $sort = 'name';

    #[Url(as: 'dir')]
    public string $direction = 'asc';

    public string $filter = '';

    public string $query = '';

    public string $mode = 'data';

    public string $match = 'contains';

    public string $column = '';

    /** @var list<string> Databases to search; empty means all. */
    public array $scope = [];

    /** @var array{results: list<array<string, mixed>>, searched: int, skipped: list<string>, truncated: bool}|null */
    public ?array $found = null;

    public ?string $searchError = null;

    public function runSearch(DatabaseBrowser $browser): void
    {
        $this->searchError = null;
        $this->found = null;

        if (trim($this->query) === '') {
            $this->searchError = 'Type what to look for.';

            return;
        }

        try {
            $this->found = $browser->search($this->query, [
                'mode' => $this->mode,
                'match' => $this->match,
                'column' => $this->column,
                'databases' => $this->scope,
            ]);
        } catch (Throwable $exception) {
            $this->searchError = $exception->getMessage();
        }
    }

    public function sortBy(string $column): void
    {
        // The same column again reverses the order; a new one starts A–Z for names, largest first for numbers.
        $this->direction = $this->sort === $column ? ($this->direction === 'asc' ? 'desc' : 'asc') : ($column === 'name' ? 'asc' : 'desc');
        $this->sort = in_array($column, ['name', 'tables', 'size'], true) ? $column : 'name';
    }

    public function backup(BackupManager $backups, string $database): void
    {
        try {
            $backups->backupDatabases([$database]);
            $this->toast('Backup of '.$database.' started. It shows under Backups → Databases.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function render(DatabaseBrowser $browser)
    {
        $databases = [];
        $error = null;

        try {
            $databases = $browser->databases();
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $shown = collect($databases)
            ->filter(fn (array $database): bool => $this->filter === '' || str_contains(strtolower($database['name']), strtolower(trim($this->filter))))
            ->sortBy(in_array($this->sort, ['tables', 'size'], true) ? $this->sort : 'name', SORT_NATURAL | SORT_FLAG_CASE, $this->direction === 'desc')
            ->values();

        return view('livewire.database.index', [
            'databases' => $shown,
            'allDatabases' => array_column($databases, 'name'),
            'totalSize' => array_sum(array_column($databases, 'size')),
            'error' => $error,
            'changes' => $this->tab === 'changes' ? DatabaseChange::query()->with('user')->latest('id')->limit(200)->get() : collect(),
        ])->layout('layouts.app')->title('Database');
    }
}
