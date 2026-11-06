<?php

namespace App\Livewire\Concerns;

use App\Services\DatabaseBrowser;
use App\Services\DatabaseSql;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The SQL box of the Database tool: run statements in one database and download a result as
 * INSERT statements. Used on the Database page (with a database picker), a database, and a table.
 */
trait RunsSql
{
    use KeepsSqlHistory;

    public string $sql = '';

    public ?string $sqlError = null;

    /** @var list<array<string, mixed>> */
    public array $sqlResults = [];

    /**
     * The database the statements run in.
     */
    abstract protected function sqlDatabase(): string;

    protected function runFromHistory(string $sql): void
    {
        $this->runSql(app(DatabaseSql::class), '', $sql);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function historyTarget(): array
    {
        return ['mysql', $this->sqlDatabase()];
    }

    /**
     * The tables and columns of the database the SQL box runs in, for the editor's autocomplete.
     *
     * @return array<string, list<string>>
     */
    public function sqlSchema(DatabaseBrowser $browser): array
    {
        if ($this->sqlDatabase() === '') {
            return [];
        }

        try {
            return $browser->schema($this->sqlDatabase());
        } catch (Throwable) {
            return [];
        }
    }

    public function runSql(DatabaseSql $runner, string $selection = '', string $sql = ''): void
    {
        if ($sql !== '') {
            $this->sql = $sql;
        }

        $this->sqlResults = [];
        $this->sqlError = null;

        if ($this->sqlDatabase() === '') {
            $this->sqlError = 'Choose a database to run it in.';

            return;
        }

        $ranSql = trim($selection) !== '' ? $selection : $this->sql;
        $started = microtime(true);
        $ran = $runner->run($this->sqlDatabase(), $ranSql);
        $results = $ran['results'];
        $this->saveSqlRun(
            $ranSql,
            collect($results)->contains(fn (array $result): bool => $result['kind'] === 'write') ? 'write' : 'read',
            $ran['error'] === null,
            collect($results)->sum(fn (array $result): int => count($result['rows'] ?? [])),
            collect($results)->sum(fn (array $result): int => (int) ($result['affected'] ?? 0)),
            $ran['error'],
            $started,
            collect($results)->pluck('changes')->filter()->values()->all(),
        );

        // The captured rows live in the history; the results on screen do not need them.
        $this->sqlResults = array_map(fn (array $result): array => array_diff_key($result, ['changes' => true]), $ran['results']);
        $this->sqlError = $ran['error'];
    }

    public function downloadInserts(DatabaseSql $runner, int $index): StreamedResponse
    {
        $result = $this->sqlResults[$index] ?? null;
        abort_unless(is_array($result) && ($result['kind'] ?? '') === 'rows' && ($result['rows'] ?? []) !== [], 404);

        $table = (string) ($result['table'] ?: 'rows');
        $body = $runner->inserts($table, $result['columns'], $result['rows']);
        $name = $this->sqlDatabase().'-'.$table.'-inserts.sql';

        return response()->streamDownload(function () use ($body): void {
            echo $body;
        }, $name, ['Content-Type' => 'application/sql; charset=UTF-8']);
    }
}
