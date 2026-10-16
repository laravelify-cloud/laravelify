<?php

namespace App\Support;

/**
 * Tables a database backup leaves out, like folders a file backup skips: cache, sessions, logs,
 * or data that never changes. Each entry is "database.table", or "*.table" for every database.
 */
class SkippedTables
{
    private const ENTRY = '/^([A-Za-z0-9_-]{1,64}|\*)\.([A-Za-z0-9_$-]{1,64})$/';

    /**
     * Clean entries from a list or from text (one per line, or separated by commas and spaces).
     *
     * @param  array<int, mixed>|string|null  $input
     * @return list<string>
     */
    public static function normalize(array|string|null $input): array
    {
        $items = is_array($input) ? $input : (preg_split('/[\s,;]+/', (string) $input) ?: []);
        $entries = [];

        foreach ($items as $item) {
            $entry = trim((string) $item);

            if ($entry !== '' && preg_match(self::ENTRY, $entry) === 1) {
                $entries[] = $entry;
            }
        }

        $entries = array_values(array_unique($entries));
        sort($entries);

        return $entries;
    }

    /**
     * Entries that are not valid, to tell the user.
     *
     * @return list<string>
     */
    public static function invalid(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,;]+/', $text) ?: []),
            fn (string $entry): bool => $entry !== '' && preg_match(self::ENTRY, $entry) !== 1,
        ));
    }

    /**
     * The tables of one database to leave out.
     *
     * @param  list<string>  $entries
     * @return list<string>
     */
    public static function forDatabase(array $entries, string $database): array
    {
        $tables = [];

        foreach ($entries as $entry) {
            if (preg_match(self::ENTRY, $entry, $match) === 1 && ($match[1] === '*' || $match[1] === $database)) {
                $tables[] = $match[2];
            }
        }

        return array_values(array_unique($tables));
    }
}
