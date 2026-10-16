<?php

namespace App\Livewire\Database;

use App\Livewire\Concerns\RunsSql;
use App\Livewire\PanelComponent;
use App\Models\CryptoMethod;
use App\Services\BackupManager;
use App\Services\CryptoMethods;
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
    use RunsSql;

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

    /** @var array<string, string> Filter values encrypted by a method: "methodId|value" => encrypted value. */
    public array $encryptedValues = [];

    /** The method the open record is shown decrypted with. */
    public string $decryptWith = '';

    /** @var array<string, string> Decrypted values of the open record, by column. */
    public array $decrypted = [];

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

    public bool $exportOpen = false;

    public bool $exportSingle = true;

    public string $exportDatabase = '';

    /** @var list<string> */
    public array $exportTables = [];

    public function openExport(): void
    {
        $this->exportDatabase = $this->database;
        $this->exportTables = [$this->table];
        $this->exportSingle = true;
        $this->exportOpen = true;
    }

    public function mount(string $database, string $table): void
    {
        abort_unless(DatabaseBrowser::validDatabase($database) && DatabaseBrowser::validTable($table), 404);
        $this->database = $database;
        $this->table = $table;
        $this->exportDatabase = $database;
        $this->exportTables = [$table];
        $this->perPage = in_array($this->perPage, [25, 50, 100, 250, 500], true) ? $this->perPage : 25;

        // From a related row: ?where[currency_id]=5 shows only the rows that point at it.
        foreach ((array) request()->query('where', []) as $column => $value) {
            if (is_string($column) && is_scalar($value) && preg_match('/^[A-Za-z0-9_$ -]{1,64}$/', $column) === 1) {
                $this->filters[] = ['column' => $column, 'operator' => 'equals', 'value' => (string) $value];
            }
        }

        $keys = json_decode($this->rowParam, true);

        if (is_array($keys) && $keys !== []) {
            $this->openRow(app(DatabaseBrowser::class), $keys);
        }
    }

    protected function sqlDatabase(): string
    {
        return $this->database;
    }

    public function updatedTab(string $tab): void
    {
        if ($tab === 'sql' && trim($this->sql) === '') {
            $this->sql = 'SELECT * FROM `'.$this->table.'` LIMIT 100;';
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
        $this->encryptFilterValues(app(CryptoMethods::class));
    }

    /**
     * A filter with "encrypted with" looks for the encrypted value: encrypt each new value once.
     */
    private function encryptFilterValues(CryptoMethods $crypto): void
    {
        $this->resetErrorBag('filters');

        foreach ($this->filters as $filter) {
            $methodId = (int) ($filter['crypto'] ?? 0);
            $value = (string) ($filter['value'] ?? '');
            $key = $methodId.'|'.$value;

            if ($methodId === 0 || $value === '' || isset($this->encryptedValues[$key])) {
                continue;
            }

            $method = CryptoMethod::query()->find($methodId);

            if (! $method) {
                continue;
            }

            try {
                $this->encryptedValues[$key] = $crypto->encrypt($method, $value);
            } catch (Throwable $exception) {
                $this->addError('filters', $exception->getMessage());
            }
        }
    }

    /**
     * The filters as MySQL gets them: an encrypted filter's value replaced by its encrypted form.
     *
     * @return list<array<string, mixed>>
     */
    private function filtersForQuery(): array
    {
        return array_values(array_filter(array_map(function (array $filter): ?array {
            $methodId = (int) ($filter['crypto'] ?? 0);

            if ($methodId === 0 || (string) ($filter['value'] ?? '') === '') {
                return $filter;
            }

            $encrypted = $this->encryptedValues[$methodId.'|'.$filter['value']] ?? null;

            return $encrypted === null ? null : [...$filter, 'value' => $encrypted];
        }, $this->filters)));
    }

    /**
     * Show the open record decrypted with a method: every text value it can decrypt.
     */
    public function decryptRow(CryptoMethods $crypto): void
    {
        $this->decrypted = [];
        $method = CryptoMethod::query()->find((int) $this->decryptWith);

        if (! $method) {
            return;
        }

        $columns = array_keys(array_filter($this->rowData, fn ($value): bool => is_string($value) && $value !== '' && ! str_starts_with($value, '[binary, ')));

        try {
            $plain = $crypto->decrypt($method, array_map(fn (string $column): string => (string) $this->rowData[$column], $columns));
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        foreach ($columns as $index => $column) {
            $value = $plain[$index] ?? null;

            if ($value !== null && $value !== '' && $value !== $this->rowData[$column] && mb_check_encoding($value, 'UTF-8')) {
                $this->decrypted[$column] = $value;
            }
        }

        $this->toast($this->decrypted === [] ? 'No value of this record decrypts with '.$method->name.'.' : 'Decrypted '.count($this->decrypted).' '.(count($this->decrypted) === 1 ? 'value' : 'values').' with '.$method->name.'.', $this->decrypted === [] ? 'error' : 'success');
    }

    public function sortBy(string $column): void
    {
        $this->direction = $this->sort === $column && $this->direction === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->page = 1;
    }

    /**
     * Sort as the grid asks: a column and a direction, or no column for the table's own order.
     */
    public function sortTo(?string $column, string $direction = 'asc'): void
    {
        $this->sort = $column !== null && $column !== '' ? $column : null;
        $this->direction = $direction === 'desc' ? 'desc' : 'asc';
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
        $this->decrypted = [];
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

    public function editRow(DatabaseBrowser $browser): void
    {
        $this->form = [];
        $columns = collect($browser->columns($this->database, $this->table))->keyBy('name');

        foreach ($this->rowData as $column => $value) {
            $info = $columns->get($column);
            $shown = $value === null ? '' : (string) $value;
            $this->form[$column] = ['value' => $info ? DatabaseBrowser::toInput($info, $shown) : $shown, 'null' => $value === null];
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

            $values[$column] = ! empty($field['null']) ? null : DatabaseBrowser::fromInput($info, (string) ($field['value'] ?? ''));
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
        $relations = ['out' => [], 'in' => []];
        $data = ['rows' => [], 'total' => 0, 'page' => 1, 'per_page' => $this->perPage, 'pages' => 1];
        $error = null;

        try {
            $columns = $browser->columns($this->database, $this->table);
            $keys = $browser->keyColumns($this->database, $this->table);

            if ($columns === []) {
                abort(404);
            }

            $relations = $browser->relations($this->database, $this->table);

            if ($this->tab === 'browse') {
                $data = $browser->rows($this->database, $this->table, [
                    'page' => $this->page,
                    'per_page' => $this->perPage,
                    'sort' => $this->sort,
                    'direction' => $this->direction,
                    'search' => $this->search,
                    'filters' => $this->filtersForQuery(),
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
            'relations' => $relations,
            'data' => $data,
            'error' => $error,
            'operators' => DatabaseBrowser::OPERATORS,
            'cryptoMethods' => CryptoMethod::query()->orderBy('name')->get(['id', 'name', 'decrypt_code']),
        ])->layout('layouts.app')->title($this->database.'.'.$this->table);
    }
}
