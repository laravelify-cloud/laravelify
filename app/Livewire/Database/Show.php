<?php

namespace App\Livewire\Database;

use App\Livewire\Concerns\ManagesDatabaseChanges;
use App\Livewire\Concerns\RunsSql;
use App\Livewire\PanelComponent;
use App\Models\CryptoMethod;
use App\Models\DatabaseChange;
use App\Services\BackupManager;
use App\Services\CryptoMethods;
use App\Services\DatabaseBrowser;
use Livewire\Attributes\Url;
use Throwable;

/**
 * One database: its tables (rows, size), its triggers, events, procedures, and functions (view,
 * change, create, drop), a search in it, and the changes made to it here.
 */
class Show extends PanelComponent
{
    use ManagesDatabaseChanges;
    use RunsSql;

    public string $database = '';

    #[Url]
    public string $tab = 'tables';

    #[Url]
    public string $sort = 'name';

    #[Url(as: 'dir')]
    public string $direction = 'asc';

    public string $filter = '';

    // Search in this database (shared search partial).
    public string $query = '';

    public string $mode = 'data';

    public string $match = 'contains';

    public string $column = '';

    /** @var list<string> */
    public array $scope = [];

    /** @var array<string, mixed>|null */
    public ?array $found = null;

    public ?string $searchError = null;

    /** An encryption method to run on the search text first (search for the encrypted value). */
    public string $searchCrypto = '';

    public ?string $searchedFor = null;

    public bool $exportOpen = false;

    public bool $exportSingle = false;

    public string $exportDatabase = '';

    /** @var list<string> */
    public array $exportTables = [];

    // Trigger, event, procedure, function editor.
    public bool $editorOpen = false;

    public string $editorType = 'trigger';

    public ?string $editorName = null;

    public string $editorSql = '';

    public bool $editorReadOnly = false;

    public function mount(string $database): void
    {
        abort_unless(DatabaseBrowser::validDatabase($database), 404);
        $this->database = $database;
        $this->scope = [$database];
        $this->exportDatabase = $database;
    }

    public function openExport(DatabaseBrowser $browser): void
    {
        try {
            $this->exportTables = array_column($browser->tables($this->database), 'name');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->exportSingle = false;
        $this->exportOpen = true;
    }

    protected function sqlDatabase(): string
    {
        return $this->database;
    }

    public function sortBy(string $column): void
    {
        // The same column again reverses the order; a new one starts A–Z for names, largest first for numbers.
        $this->direction = $this->sort === $column ? ($this->direction === 'asc' ? 'desc' : 'asc') : (in_array($column, ['name', 'engine'], true) ? 'asc' : 'desc');
        $this->sort = in_array($column, ['name', 'rows', 'size', 'engine', 'updated'], true) ? $column : 'name';
    }

    public function runSearch(DatabaseBrowser $browser, CryptoMethods $crypto): void
    {
        $this->searchError = null;
        $this->found = null;
        $this->searchedFor = null;

        try {
            $term = $this->query;
            $method = $this->mode === 'data' && $this->searchCrypto !== '' ? CryptoMethod::query()->find((int) $this->searchCrypto) : null;

            if ($method && trim($this->query) !== '') {
                $term = $crypto->encrypt($method, trim($this->query));
                $this->searchedFor = $term;
            }

            $this->found = $browser->search($term, ['mode' => $this->mode, 'match' => $this->match, 'column' => $this->column, 'databases' => [$this->database], 'encrypted' => $method === null && $this->mode === 'data' ? $crypto->termsForEncryptedColumns($this->query, [$this->database]) : []]);
        } catch (Throwable $exception) {
            $this->searchError = $exception->getMessage();
        }
    }

    public function backup(BackupManager $backups): void
    {
        try {
            $backups->backupDatabases([$this->database]);
            $this->toast('Backup of '.$this->database.' started. It shows under Backups → Databases.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function viewObject(DatabaseBrowser $browser, string $type, string $name): void
    {
        $this->openEditor($browser, $type, $name, true);
    }

    public function editObject(DatabaseBrowser $browser, string $type, string $name): void
    {
        $this->openEditor($browser, $type, $name, false);
    }

    public function newObject(string $type): void
    {
        abort_unless(in_array($type, DatabaseBrowser::OBJECT_TYPES, true), 404);
        $this->resetErrorBag('editorSql');
        $this->editorType = $type;
        $this->editorName = null;
        $this->editorReadOnly = false;
        $this->editorSql = match ($type) {
            'trigger' => "CREATE TRIGGER trigger_name BEFORE INSERT ON table_name\nFOR EACH ROW\nBEGIN\n    SET NEW.created_at = NOW();\nEND",
            'event' => "CREATE EVENT event_name\nON SCHEDULE EVERY 1 DAY\nDO\n    DELETE FROM table_name WHERE created_at < NOW() - INTERVAL 30 DAY",
            'procedure' => "CREATE PROCEDURE procedure_name(IN value INT)\nBEGIN\n    SELECT value;\nEND",
            default => "CREATE FUNCTION function_name(value INT) RETURNS INT\nDETERMINISTIC\nBEGIN\n    RETURN value * 2;\nEND",
        };
        $this->editorOpen = true;
    }

    public function saveObject(DatabaseBrowser $browser): void
    {
        $this->resetErrorBag('editorSql');

        try {
            $name = $browser->saveObject($this->database, $this->editorType, $this->editorSql, $this->editorName);
            $this->editorOpen = false;
            $this->toast(ucfirst($this->editorType).' '.$name.' saved. The change log can undo it.');
        } catch (Throwable $exception) {
            $this->addError('editorSql', $exception->getMessage());
        }
    }

    public function dropObject(DatabaseBrowser $browser, string $type, string $name): void
    {
        try {
            $browser->dropObject($this->database, $type, $name);
            $this->toast(ucfirst($type).' '.$name.' dropped. Its definition is in the change log, where Undo creates it again.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }

    private function openEditor(DatabaseBrowser $browser, string $type, string $name, bool $readOnly): void
    {
        try {
            $this->editorSql = $browser->definition($this->database, $type, $name);
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        }

        $this->resetErrorBag('editorSql');
        $this->editorType = $type;
        $this->editorName = $name;
        $this->editorReadOnly = $readOnly;
        $this->editorOpen = true;
    }

    public function render(DatabaseBrowser $browser)
    {
        $tables = [];
        $objects = ['trigger' => [], 'event' => [], 'procedure' => [], 'function' => []];
        $error = null;

        try {
            $tables = $browser->tables($this->database);

            if ($this->tab === 'objects') {
                $objects = $browser->objects($this->database);
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $shown = collect($tables)
            ->filter(fn (array $table): bool => $this->filter === '' || str_contains(strtolower($table['name']), strtolower(trim($this->filter))))
            ->sortBy(fn (array $table) => $table[in_array($this->sort, ['rows', 'size', 'engine', 'updated'], true) ? $this->sort : 'name'] ?? '', SORT_NATURAL | SORT_FLAG_CASE, $this->direction === 'desc')
            ->values();

        return view('livewire.database.show', [
            'tables' => $shown,
            'tableCount' => count($tables),
            'totalSize' => array_sum(array_column($tables, 'size')),
            'objects' => $objects,
            'error' => $error,
            'changes' => $this->tab === 'changes'
                ? DatabaseChange::query()->with('user')->where('database', $this->database)->latest('id')->limit(200)->get()
                : collect(),
        ])->layout('layouts.app')->title($this->database);
    }
}
