<?php

namespace App\Livewire\Concerns;

use App\Services\DatabaseBrowser;
use App\Services\DatabaseDumper;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * The tables of a database with their size, for the "skip tables" picker (loaded when a database is opened).
 */
trait ListsTables
{
    /**
     * @return array{tables: list<array{name: string, size: ?int, rows: ?int}>, error: ?string}
     */
    #[Renderless]
    public function tablesOf(DatabaseBrowser $browser, DatabaseDumper $dumper, string $database): array
    {
        if (! DatabaseDumper::validName($database)) {
            return ['tables' => [], 'error' => 'Not a database name.'];
        }

        try {
            return ['tables' => array_map(fn (array $table): array => ['name' => $table['name'], 'size' => $table['size'], 'rows' => $table['rows']], $browser->tables($database)), 'error' => null];
        } catch (Throwable) {
            // Without the sizes, the names from mysql still let tables be picked.
        }

        try {
            return ['tables' => array_map(fn (string $name): array => ['name' => $name, 'size' => null, 'rows' => null], $dumper->tables($database)), 'error' => null];
        } catch (Throwable $exception) {
            return ['tables' => [], 'error' => $exception->getMessage()];
        }
    }
}
