<?php

namespace App\Livewire\Concerns;

use App\Services\DatabaseDumper;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * The tables of a database, for the "skip tables" picker (loaded when a database is opened).
 */
trait ListsTables
{
    /**
     * @return array{tables: list<string>, error: ?string}
     */
    #[Renderless]
    public function tablesOf(DatabaseDumper $dumper, string $database): array
    {
        if (! DatabaseDumper::validName($database)) {
            return ['tables' => [], 'error' => 'Not a database name.'];
        }

        try {
            return ['tables' => $dumper->tables($database), 'error' => null];
        } catch (Throwable $exception) {
            return ['tables' => [], 'error' => $exception->getMessage()];
        }
    }
}
