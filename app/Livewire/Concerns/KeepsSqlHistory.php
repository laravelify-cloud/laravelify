<?php

namespace App\Livewire\Concerns;

use App\Models\SqlHistory;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The SQL tab's history for one database: every run with what came back, the rows an UPDATE or
 * DELETE changed (before and after), and Run again / Edit to put it back in the editor.
 */
trait KeepsSqlHistory
{
    public string $historySearch = '';

    /** '' for every run, or read, write, error. */
    public string $historyKind = '';

    public int $historyShown = 25;

    /** The run whose changed rows are open. */
    public ?int $historyOpen = null;

    /**
     * The engine and database the SQL tab runs in now; the database is '' when none is chosen.
     *
     * @return array{0: string, 1: string}
     */
    abstract protected function historyTarget(): array;

    /**
     * Save a run.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    protected function saveSqlRun(string $sql, string $kind, bool $ok, ?int $rows, ?int $affected, ?string $error, float $started, array $changes = []): void
    {
        [$engine, $database] = $this->historyTarget();

        if ($database === '' || trim($sql) === '') {
            return;
        }

        SqlHistory::record($engine, $database, [
            'sql' => trim($sql),
            'kind' => $ok ? $kind : 'error',
            'ok' => $ok,
            'rows' => $rows,
            'affected' => $affected,
            'error' => $error !== null ? mb_substr($error, 0, 2000) : null,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'changes' => $changes !== [] ? $changes : null,
        ]);
        unset($this->sqlHistory, $this->sqlHistoryCounts);
    }

    /**
     * @return Collection<int, SqlHistory>
     */
    #[Computed]
    public function sqlHistory(): Collection
    {
        [$engine, $database] = $this->historyTarget();

        if ($database === '') {
            return collect();
        }

        return SqlHistory::query()
            ->with('user:id,name')
            ->where('engine', $engine)
            ->where('database', $database)
            ->when($this->historyKind !== '', fn ($query) => $query->where('kind', $this->historyKind))
            ->when(trim($this->historySearch) !== '', fn ($query) => $query->where('sql', 'like', '%'.addcslashes(trim($this->historySearch), '%_\\').'%'))
            ->latest('id')
            ->limit(max(1, min(500, $this->historyShown)) + 1)
            ->get();
    }

    public function showMoreHistory(): void
    {
        $this->historyShown = min(500, $this->historyShown + 25);
    }

    public function toggleHistoryChanges(int $id): void
    {
        $this->historyOpen = $this->historyOpen === $id ? null : $id;
    }

    /** Open the SQL tab with a run back in the editor, and run it when $run. */
    public function useHistory(int $id, bool $run = false): void
    {
        $entry = $this->historyEntry($id);

        if (! $entry) {
            return;
        }

        $this->sql = $entry->sql;
        $this->tab = 'sql';
        $this->dispatch('sql-set', sql: $entry->sql, run: false);

        if ($run) {
            $this->runFromHistory($entry->sql);
        }
    }

    /** Run SQL from the history in the SQL tab. */
    abstract protected function runFromHistory(string $sql): void;

    /**
     * How many runs of each kind, for the filter.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function sqlHistoryCounts(): array
    {
        [$engine, $database] = $this->historyTarget();

        if ($database === '') {
            return [];
        }

        $counts = SqlHistory::query()->where('engine', $engine)->where('database', $database)
            ->selectRaw('kind, COUNT(*) AS total')->groupBy('kind')->pluck('total', 'kind')->map(fn ($total) => (int) $total)->all();

        return ['' => array_sum($counts)] + $counts;
    }

    public function deleteHistory(int $id): void
    {
        $this->historyEntry($id)?->delete();
        unset($this->sqlHistory, $this->sqlHistoryCounts);
    }

    public function clearHistory(): void
    {
        [$engine, $database] = $this->historyTarget();
        SqlHistory::query()->where('engine', $engine)->where('database', $database)->delete();
        $this->historyOpen = null;
        unset($this->sqlHistory, $this->sqlHistoryCounts);
        $this->toast('The SQL history of '.$database.' is cleared.');
    }

    private function historyEntry(int $id): ?SqlHistory
    {
        [$engine, $database] = $this->historyTarget();

        return SqlHistory::query()->where('engine', $engine)->where('database', $database)->find($id);
    }
}
