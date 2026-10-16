<?php

namespace App\Livewire\Database;

use App\Livewire\PanelComponent;
use App\Services\BackupManager;
use App\Services\DatabaseBrowser;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * One table: its rows a page at a time (sort, search in every column, filters on single columns),
 * one record in a dialog to read, change, or delete, new rows, and its structure. Changes go to the
 * change log, and deleting can save a copy of the table first.
 */
class Table extends PanelComponent
{
    public string $database = '';

    public string $table = '';

    #[Url]
    public string $tab = 'browse';

    #[Url]
    public int $page = 1;

    #[Url(as: 'per')]
    public int $perPage = 25;

    #[Url]
    public ?string $sort = null;

    #[Url(as: 'dir')]
    public string $direction = 'asc';

    #[Url(as: 'q')]
    public string $search = '';

    /** @var list<array{column: string, operator: string, value: string}> */
    public array $filters = [];

    /** A record to open (from a search result): its keys as JSON. */
    #[Url(as: 'row')]
    public string $rowParam = '';

    public bool $rowOpen = false;

    /** @var array<string, mixed> */
    public array $rowKeys = [];

    /** @var array<string, mixed> */
    public array $rowData = [];

    public bool $editing = false;

    public bool $creating = false;

    /** @var array<string, array{value: string, null: bool}> */
    public array $form = [];

    public bool $deleteOpen = false;

    /** @var list<array<string, mixed>> */
    public array $deleteKeys = [];

    public bool $saveCopy = true;

    public bool $backupFirst = false;

    public function mount(string $database, string $table): void
    {
        abort_unless(DatabaseBrowser::validDatabase($database) && DatabaseBrowser::validTable($table), 404);
        $this->database = $database;
        $this->table = $table;
        $this->perPage = in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 25;

        $keys = json_decode($this->rowParam, true);

        if (is_array($keys) && $keys !== []) {
            $this->openRow(app(DatabaseBrowser::class), $keys);
        }
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
    }

    public function updatedFilters(): void
    {
        $this->page = 1;
    }

    public function sortBy(string $column): void
    {
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->page = 1;
    }

    public function goTo(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function addFilter(string $column = ''): void
    {
        $this->filters[] = ['column' => $column, 'operator' => 'contains', 'value' => ''];
    }

    public function removeFilter(int $index): void
    {
        unset($this->filters[$index]);
        $this->filters = array_values($this->filters);
        $this->page = 1;
    }

    // One record

    /**
     * @param  array<string, mixed>  $keys
     */
    public function openRow(DatabaseBrowser $browser, array $keys): void
    {
        try {
            $row = $browser->row($this->database, $this->table, $keys);
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        if ($row === null) {
            $this->toast('This row is not there any more.', 'error');

            return;
        }

        $this->rowKeys = $keys;
        $this->rowData = $row;
        $this->rowParam = (string) json_encode($keys);
        $this->editing = false;
        $this->creating = false;
        $this->rowOpen = true;
    }

    public function closeRow(): void
    {
        $this->rowOpen = false;
        $this->editing = false;
        $this->creating = false;
        $this->rowParam = '';
        $this->resetErrorBag();
    }

    public function editRow(): void
    {
        $this->form = [];

        foreach ($this->rowData as $column => $value) {
            $this->form[$column] = ['value' => $value === null ? '' : (string) $value, 'null' => $value === null];
        }

        $this->editing = true;
    }

    public function newRow(DatabaseBrowser $browser): void
    {
        $this->form = [];

        foreach ($browser->columns($this->database, $this->table) as $column) {
            $this->form[$column['name']] = ['value' => $column['default'] !== null && ! str_contains($column['extra'], 'DEFAULT_GENERATED') ? (string) $column['default'] : '', 'null' => false];
        }

        $this->rowKeys = [];
        $this->rowData = [];
        $this->creating = true;
        $this->editing = true;
        $this->rowOpen = true;
    }

    public function saveRow(DatabaseBrowser $browser): void
    {
        $this->resetErrorBag();
        $columns = collect($browser->columns($this->database, $this->table))->keyBy('name');
        $values = [];

        foreach ($this->form as $column => $field) {
            $info = $columns->get($column);

            if (! $info || str_starts_with((string) ($field['value'] ?? ''), '[binary, ')) {
                continue;
            }

            $empty = ($field['value'] ?? '') === '';

            // A new row leaves blank fields to MySQL: auto-increment, defaults, and NULL where allowed.
            if ($this->creating && $empty && empty($field['null']) && (str_contains($info['extra'], 'auto_increment') || $info['default'] !== null || $info['nullable'] || str_contains($info['extra'], 'DEFAULT_GENERATED'))) {
                continue;
            }

            $values[$column] = ! empty($field['null']) ? null : (string) ($field['value'] ?? '');
        }

        try {
            $keys = $this->creating
                ? $browser->insertRow($this->database, $this->table, $values)
                : $browser->updateRow($this->database, $this->table, $this->rowKeys, $values);
        } catch (Throwable $exception) {
            $this->addError('form', $this->message($exception));

            return;
        }

        $this->toast($this->creating ? 'Row added. The change log can undo it.' : 'Row saved. The change log can undo it.');
        $this->openRow($browser, $keys);
    }

    // Deleting

    /**
     * @param  list<array<string, mixed>>  $keys
     */
    public function askDelete(array $keys): void
    {
        $this->deleteKeys = array_values(array_filter($keys, 'is_array'));

        if ($this->deleteKeys === []) {
            return;
        }

        $this->saveCopy = true;
        $this->backupFirst = false;
        $this->deleteOpen = true;
    }

    public function deleteOpenRow(): void
    {
        $this->askDelete([$this->rowKeys]);
    }

    public function confirmDelete(DatabaseBrowser $browser, BackupManager $backups): void
    {
        $snapshot = null;

        try {
            if ($this->backupFirst) {
                $backups->backupDatabases([$this->database]);
            }

            if ($this->saveCopy) {
                $snapshot = $browser->snapshotTable($this->database, $this->table);
            }

            $deleted = $browser->deleteRows($this->database, $this->table, $this->deleteKeys, $snapshot);
        } catch (Throwable $exception) {
            $this->toast($this->message($exception), 'error');

            return;
        }

        $this->deleteOpen = false;
        $this->closeRow();
        $this->dispatch('rows-deleted');
        $this->toast($deleted.' '.($deleted === 1 ? 'row' : 'rows').' deleted. Each can be undone from the change log'.($snapshot ? ', and a copy of the whole table was saved first.' : '.'));
    }

    private function message(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return preg_match('/SQLSTATE\[[^\]]+\]: [^:]+: \d+ (.+?)(?: \(Connection:|$)/s', $message, $match) === 1 ? 'MySQL said: '.trim($match[1]) : $message;
    }

    public function render(DatabaseBrowser $browser)
    {
        $columns = [];
        $keys = [];
        $data = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $this->perPage, 'pages' => 1];
        $error = null;

        try {
            $columns = $browser->columns($this->database, $this->table);
            $keys = $browser->keyColumns($this->database, $this->table);

            if ($columns === []) {
                abort(404);
            }

            if ($this->tab === 'browse') {
                $data = $browser->rows($this->database, $this->table, [
                    'page' => $this->page,
                    'per_page' => $this->perPage,
                    'sort' => $this->sort,
                    'direction' => $this->direction,
                    'search' => $this->search,
                    'filters' => $this->filters,
                ]);
                $this->page = $data['page'];
            }
        } catch (Throwable $exception) {
            if ($exception instanceof HttpException) {
                throw $exception;
            }

            $error = $this->message($exception);
        }

        return view('livewire.database.table', [
            'columns' => $columns,
            'keyColumns' => $keys,
            'data' => $data,
            'error' => $error,
            'operators' => DatabaseBrowser::OPERATORS,
        ])->layout('layouts.app')->title($this->database.'.'.$this->table);
    }
}
