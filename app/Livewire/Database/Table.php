<?php

namespace App\Livewire\Database;

use App\Livewire\Concerns\RunsSql;
use App\Livewire\PanelComponent;
use App\Models\CryptoMethod;
use App\Models\EncryptedColumn;
use App\Services\BackupManager;
use App\Services\CryptoMethods;
use App\Services\DatabaseBrowser;
use Livewire\Attributes\Renderless;
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

    /** @var list<string> Encrypted columns the grid shows as stored instead of decrypted. */
    public array $rawColumns = [];

    /** @var array<string, string> Encrypted columns of the form shown decrypted: the decrypted value they started with. */
    public array $formPlain = [];

    /** @var array<string, CryptoMethod>|null */
    private ?array $encryptedColumnsCache = null;

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

        if ($this->filters !== [] || $this->search !== '') {
            $this->encryptFilterValues(app(CryptoMethods::class));
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
        $this->encryptFilterValues(app(CryptoMethods::class));
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
     * A filter with "encrypted with", an "is exactly" filter on an encrypted column, and the search
     * (for the encrypted columns) look for encrypted values: encrypt each new value once.
     */
    private function encryptFilterValues(CryptoMethods $crypto): void
    {
        $this->resetErrorBag('filters');
        $wanted = [];

        foreach ($this->filters as $filter) {
            $methodId = $this->filterMethodId($filter);
            $value = (string) ($filter['value'] ?? '');

            if ($methodId !== 0 && $value !== '') {
                $wanted[$methodId.'|'.$value] = [$methodId, $value, true];
            }
        }

        if (trim($this->search) !== '') {
            foreach ($this->searchedColumns() as $method) {
                $wanted[$method->id.'|'.trim($this->search)] ??= [$method->id, trim($this->search), false];
            }
        }

        foreach ($wanted as $key => [$methodId, $value, $reportErrors]) {
            if (isset($this->encryptedValues[$key])) {
                continue;
            }

            $method = CryptoMethod::query()->with('website')->find($methodId);

            if (! $method) {
                continue;
            }

            try {
                $this->encryptedValues[$key] = $crypto->encrypt($method, $value);
            } catch (Throwable $exception) {
                if ($reportErrors) {
                    $this->addError('filters', $exception->getMessage());
                }
            }
        }
    }

    /**
     * The method a filter's value is encrypted with: the one chosen on the filter, or the column's
     * own for "is exactly" and "is not" on a column shown decrypted.
     *
     * @param  array<string, mixed>  $filter
     */
    private function filterMethodId(array $filter): int
    {
        $chosen = (int) ($filter['crypto'] ?? 0);

        if ($chosen !== 0) {
            return $chosen;
        }

        $column = (string) ($filter['column'] ?? '');
        $method = $this->encryptedColumns()[$column] ?? null;

        return $method && ! in_array($column, $this->rawColumns, true) && in_array($filter['operator'] ?? '', ['equals', 'not_equals'], true) ? $method->id : 0;
    }

    /**
     * The filters as MySQL gets them: an encrypted filter's value replaced by its encrypted form.
     *
     * @return list<array<string, mixed>>
     */
    private function filtersForQuery(): array
    {
        return array_values(array_filter(array_map(function (array $filter): ?array {
            $methodId = $this->filterMethodId($filter);

            if ($methodId === 0 || (string) ($filter['value'] ?? '') === '') {
                return $filter;
            }

            $encrypted = $this->encryptedValues[$methodId.'|'.$filter['value']] ?? null;

            return $encrypted === null ? null : [...$filter, 'value' => $encrypted];
        }, $this->filters)));
    }

    /**
     * The search text encrypted for each encrypted column, so the search finds those values too.
     *
     * @return array<string, string>
     */
    private function searchExact(): array
    {
        $term = trim($this->search);

        if ($term === '') {
            return [];
        }

        $exact = [];

        foreach ($this->searchedColumns() as $column => $method) {
            if (isset($this->encryptedValues[$method->id.'|'.$term])) {
                $exact[$column] = $this->encryptedValues[$method->id.'|'.$term];
            }
        }

        return $exact;
    }

    /**
     * Columns this table keeps encrypted, with the method of each.
     *
     * @return array<string, CryptoMethod>
     */
    private function encryptedColumns(): array
    {
        return $this->encryptedColumnsCache ??= EncryptedColumn::forTable($this->database, $this->table);
    }

    /**
     * Encrypted columns the search looks in (not those shown as stored).
     *
     * @return array<string, CryptoMethod>
     */
    private function searchedColumns(): array
    {
        return array_filter($this->encryptedColumns(), fn (CryptoMethod $method, string $column): bool => ! in_array($column, $this->rawColumns, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Encrypted columns the grid shows decrypted: not key columns (rows are found by their stored
     * keys), not those shown as stored, and only with a method that can decrypt.
     *
     * @param  list<string>  $keyColumns
     * @return array<string, CryptoMethod>
     */
    private function decryptedColumns(array $keyColumns): array
    {
        return array_filter($this->searchedColumns(), fn (CryptoMethod $method, string $column): bool => $method->canDecrypt() && ! in_array($column, $keyColumns, true), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Rows with their encrypted columns decrypted, one run per method. A value that does not decrypt
     * stays as stored.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, CryptoMethod>  $columns
     * @return list<array<string, mixed>>
     */
    private function decryptRows(array $rows, array $columns, ?string &$error = null): array
    {
        $byMethod = [];

        foreach ($columns as $column => $method) {
            $byMethod[$method->id]['method'] = $method;
            $byMethod[$method->id]['columns'][] = $column;
        }

        foreach ($byMethod as ['method' => $method, 'columns' => $methodColumns]) {
            $values = [];

            foreach ($rows as $row) {
                foreach ($methodColumns as $column) {
                    $value = $row[$column] ?? null;

                    if (is_string($value) && $value !== '' && ! str_starts_with($value, '[binary, ')) {
                        $values[$value] = $value;
                    }
                }
            }

            if ($values === []) {
                continue;
            }

            $values = array_values($values);

            try {
                $plain = app(CryptoMethods::class)->decrypt($method, $values);
            } catch (Throwable $exception) {
                $error = $exception->getMessage();

                continue;
            }

            $decrypted = [];

            foreach ($values as $index => $value) {
                $result = $plain[$index] ?? null;

                if ($result !== null && $result !== '' && mb_check_encoding($result, 'UTF-8')) {
                    $decrypted[$value] = $result;
                }
            }

            foreach ($rows as $index => $row) {
                foreach ($methodColumns as $column) {
                    $value = $row[$column] ?? null;

                    if (is_string($value) && isset($decrypted[$value])) {
                        $rows[$index][$column] = $decrypted[$value];
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Keep a column encrypted with a method (the grid, filters, search, and the row form then show
     * and take its values decrypted), or stop. Only the setting is saved; the data stays as it is.
     */
    public function setColumnEncryption(DatabaseBrowser $browser, string $column, int|string|null $methodId = null): void
    {
        if (! in_array($column, array_column($browser->columns($this->database, $this->table), 'name'), true)) {
            return;
        }

        $method = (int) $methodId === 0 ? null : CryptoMethod::query()->find((int) $methodId);

        if ($method === null) {
            EncryptedColumn::query()->where(['database' => $this->database, 'table' => $this->table, 'column' => $column])->delete();
            $this->toast($column.' is shown as stored.');
        } else {
            EncryptedColumn::query()->updateOrCreate(
                ['database' => $this->database, 'table' => $this->table, 'column' => $column],
                ['crypto_method_id' => $method->id],
            );
            $this->toast(in_array($column, $browser->keyColumns($this->database, $this->table), true)
                ? $column.' is marked encrypted with '.$method->name.'. As a key column the grid keeps it as stored; the record shows it decrypted.'
                : $column.' is shown decrypted with '.$method->name.'.');
        }

        $this->encryptedColumnsCache = null;
        $this->rawColumns = array_values(array_diff($this->rawColumns, [$column]));
        $this->encryptFilterValues(app(CryptoMethods::class));
    }

    /**
     * Show an encrypted column's values as stored in the grid, or decrypted again.
     */
    public function toggleRawColumn(string $column): void
    {
        $this->rawColumns = in_array($column, $this->rawColumns, true)
            ? array_values(array_diff($this->rawColumns, [$column]))
            : [...$this->rawColumns, $column];
        $this->encryptFilterValues(app(CryptoMethods::class));
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
     * A block of rows for the grid as it scrolls (rows start to end, in the grid's sort order), with
     * the search and filters applied in MySQL. The page is not drawn again.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, error?: string}
     */
    #[Renderless]
    public function fetchRows(DatabaseBrowser $browser, int $start, int $end, ?string $sort = null, string $direction = 'asc'): array
    {
        $this->sort = $sort !== null && $sort !== '' ? $sort : null;
        $this->direction = $direction === 'desc' ? 'desc' : 'asc';

        try {
            $data = $browser->rows($this->database, $this->table, [
                'offset' => max(0, $start),
                'limit' => max(1, min(500, $end - $start)),
                'sort' => $this->sort,
                'direction' => $this->direction,
                'search' => $this->search,
                'search_exact' => $this->searchExact(),
                'filters' => $this->filtersForQuery(),
            ]);
            $columns = $this->decryptedColumns($browser->keyColumns($this->database, $this->table));
        } catch (Throwable $exception) {
            return ['rows' => [], 'total' => 0, 'error' => $this->message($exception)];
        }

        $error = null;
        $rows = $this->decryptRows($data['rows'], $columns, $error);

        return ['rows' => $rows, 'total' => $data['total'], ...($error === null ? [] : ['error' => $error])];
    }

    /**
     * The values of one column with their counts, for the header filter's list.
     *
     * An encrypted column lists its stored values with the decrypted text as the label, and the
     * search looks in the decrypted text.
     *
     * @return array{values: list<array{value: ?string, count: int, label?: string}>, more: bool, error?: string}
     */
    #[Renderless]
    public function columnValues(DatabaseBrowser $browser, string $column, string $find = ''): array
    {
        try {
            $method = $this->decryptedColumns($browser->keyColumns($this->database, $this->table))[$column] ?? null;

            if ($method === null) {
                return $browser->distinctValues($this->database, $this->table, $column, $this->search, $this->filtersForQuery(), $find, 200, $this->searchExact());
            }

            $find = trim($find);
            $list = $browser->distinctValues($this->database, $this->table, $column, $this->search, $this->filtersForQuery(), '', $find === '' ? 200 : 1000, $this->searchExact());
        } catch (Throwable $exception) {
            return ['values' => [], 'more' => false, 'error' => $this->message($exception)];
        }

        $error = null;
        $rows = array_map(fn (array $item): array => [$column => $item['value']], $list['values']);
        $plain = $this->decryptRows($rows, [$column => $method], $error);
        $values = [];

        foreach ($list['values'] as $index => $item) {
            $label = $plain[$index][$column];

            if ($find !== '' && ($label === null || ! str_contains(mb_strtolower((string) $label), mb_strtolower($find)))) {
                continue;
            }

            $values[] = $label !== $item['value'] && $label !== null ? [...$item, 'label' => (string) $label] : $item;
        }

        return ['values' => array_slice($values, 0, 200), 'more' => $list['more'] || count($values) > 200, ...($error === null ? [] : ['error' => $error])];
    }

    /**
     * The header filter of one column: a list of values to show (and NULLs), a condition, or none.
     *
     * @param  array{mode?: string, values?: list<mixed>, blanks?: bool, operator?: string, value?: string}  $filter
     */
    public function setColumnFilter(string $column, array $filter): void
    {
        $this->filters = array_values(array_filter($this->filters, fn (array $existing): bool => ($existing['column'] ?? '') !== $column));

        if (($filter['mode'] ?? '') === 'values') {
            $this->filters[] = [
                'column' => $column,
                'operator' => 'in',
                'values' => array_values(array_map('strval', array_filter((array) ($filter['values'] ?? []), 'is_scalar'))),
                'blanks' => (bool) ($filter['blanks'] ?? false),
                'value' => '',
            ];
        } elseif (($filter['mode'] ?? '') === 'condition' && array_key_exists((string) ($filter['operator'] ?? ''), DatabaseBrowser::OPERATORS)) {
            $this->filters[] = ['column' => $column, 'operator' => (string) $filter['operator'], 'value' => (string) ($filter['value'] ?? '')];
        }

        $this->updatedFilters();
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
        $this->decrypted = $this->decryptedRecord($row);
        $this->rowParam = (string) json_encode($keys);
        $this->editing = false;
        $this->creating = false;
        $this->rowOpen = true;
    }

    /**
     * The open record's encrypted columns, decrypted (every one, key columns too).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function decryptedRecord(array $row): array
    {
        $columns = array_filter($this->encryptedColumns(), fn (CryptoMethod $method): bool => $method->canDecrypt());

        if ($columns === []) {
            return [];
        }

        $error = null;
        $plain = $this->decryptRows([$row], $columns, $error)[0];

        if ($error !== null) {
            $this->toast($error, 'error');
        }

        return array_filter(
            array_map(fn ($value): ?string => is_string($value) ? $value : null, array_intersect_key($plain, $columns)),
            fn (?string $value, string $column): bool => $value !== null && $value !== $row[$column],
            ARRAY_FILTER_USE_BOTH,
        );
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
        $this->formPlain = [];
        $columns = collect($browser->columns($this->database, $this->table))->keyBy('name');

        foreach ($this->rowData as $column => $value) {
            $info = $columns->get($column);
            $shown = $value === null ? '' : (string) $value;

            // An encrypted column is edited decrypted, and encrypted again when saved.
            if (isset($this->decrypted[$column], $this->encryptedColumns()[$column])) {
                $this->formPlain[$column] = $this->decrypted[$column];
                $this->form[$column] = ['value' => $this->decrypted[$column], 'null' => false];

                continue;
            }
            $this->form[$column] = ['value' => $info ? DatabaseBrowser::toInput($info, $shown) : $shown, 'null' => $value === null];
        }

        $this->editing = true;
    }

    public function newRow(DatabaseBrowser $browser): void
    {
        $this->form = [];
        $this->formPlain = [];

        foreach ($browser->columns($this->database, $this->table) as $column) {
            $this->form[$column['name']] = ['value' => $column['default'] !== null && ! str_contains($column['extra'], 'DEFAULT_GENERATED') ? (string) $column['default'] : '', 'null' => false];
        }

        $this->rowKeys = [];
        $this->rowData = [];
        $this->creating = true;
        $this->editing = true;
        $this->rowOpen = true;
    }

    public function saveRow(DatabaseBrowser $browser, CryptoMethods $crypto): void
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

            $method = $this->encryptedColumns()[$column] ?? null;
            $encrypt = $method !== null && empty($field['null']) && ! $empty && ($this->creating || array_key_exists($column, $this->formPlain));

            // An encrypted column left as it was keeps its stored value.
            if (! $this->creating && array_key_exists($column, $this->formPlain) && empty($field['null']) && (string) ($field['value'] ?? '') === $this->formPlain[$column]) {
                continue;
            }

            if ($encrypt) {
                try {
                    $values[$column] = $crypto->encrypt($method, (string) $field['value']);
                } catch (Throwable $exception) {
                    $this->addError('form', $column.': '.$exception->getMessage());

                    return;
                }

                continue;
            }

            $values[$column] = ! empty($field['null']) ? null : DatabaseBrowser::fromInput($info, (string) ($field['value'] ?? ''));
        }

        if (! $this->creating && $values === []) {
            $this->toast('Nothing changed.');
            $this->openRow($browser, $this->rowKeys);

            return;
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
                // The grid fetches its rows as it scrolls (fetchRows); the page only needs the total.
                $data = $browser->rows($this->database, $this->table, [
                    'offset' => 0,
                    'limit' => 1,
                    'sort' => $this->sort,
                    'direction' => $this->direction,
                    'search' => $this->search,
                    'search_exact' => $this->searchExact(),
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
            'encryptedColumns' => $this->encryptedColumns(),
        ])->layout('layouts.app')->title($this->database.'.'.$this->table);
    }
}
