{{-- An SQL result in the rows grid. $columns: names; $rows: lists of values in that order. --}}
@php
    $names = [];
    foreach (array_values($columns) as $column) {
        $name = (string) $column;
        for ($n = 2; in_array($name, $names, true); $n++) {
            $name = $column.' ('.$n.')';
        }
        $names[] = $name;
    }
    $numeric = array_map(fn (int $index): bool => $rows !== [] && collect($rows)->every(fn (array $row) => ($row[$index] ?? null) === null || is_int($row[$index]) || is_float($row[$index]) || (is_string($row[$index]) && preg_match('/^-?\d+(\.\d+)?$/', $row[$index]) === 1)), array_keys($names));
@endphp
@include('livewire.database.partials.local-grid', ['spec' => [
    'key' => 'sql-result',
    'search' => 'Search the result',
    'empty' => 'No rows match.',
    'columns' => array_map(fn (string $name, bool $isNumber): array => ['name' => $name, 'numeric' => $isNumber], $names, $numeric),
    'rows' => array_map(fn (array $row): array => array_combine($names, array_pad(array_slice(array_values($row), 0, count($names)), count($names), null)), $rows),
]])
