<?php

namespace App\Livewire\Concerns;

use App\Services\DatabaseSql;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The SQL box of the Database tool: run statements in one database and download a result as
 * INSERT statements. Used on the Database page (with a database picker), a database, and a table.
 */
trait RunsSql
{
    public string $sql = '';

    public ?string $sqlError = null;

    /** @var list<array<string, mixed>> */
    public array $sqlResults = [];

    /**
     * The database the statements run in.
     */
    abstract protected function sqlDatabase(): string;

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

        $ran = $runner->run($this->sqlDatabase(), trim($selection) !== '' ? $selection : $this->sql);
        $this->sqlResults = $ran['results'];
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
