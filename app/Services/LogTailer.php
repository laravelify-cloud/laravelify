<?php

namespace App\Services;

use App\Models\LogCursor;
use App\Models\Website;

/**
 * Reads what was appended to a log file since the last run. The position is
 * stored per website and source, and starts over when the file was rotated,
 * truncated, or replaced by a newer file (Laravel daily logs).
 */
class LogTailer
{
    /**
     * Complete new lines, or null when there is nothing new. Call commit() with
     * the result after the lines were stored.
     *
     * @param  int  $firstRead  Bytes to read from the end the first time a file is seen (0 = from the start).
     * @return array{text: string, cursor: LogCursor, offset: int}|null
     */
    public function read(Website $website, string $source, string $path, int $maxBytes, int $firstRead = 0): ?array
    {
        clearstatcache(true, $path);

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $size = (int) filesize($path);
        $inode = (int) (@fileinode($path) ?: 0);
        $cursor = LogCursor::query()->firstOrNew(['website_id' => $website->id, 'source' => $source]);

        if (! $cursor->exists) {
            $offset = $firstRead > 0 ? max(0, $size - $firstRead) : 0;
        } elseif ($cursor->path !== $path || $cursor->inode !== $inode || $size < $cursor->offset) {
            $offset = 0;
        } else {
            $offset = $cursor->offset;
        }

        $cursor->fill(['path' => $path, 'inode' => $inode]);

        if ($size <= $offset) {
            $cursor->offset = $offset;
            $cursor->save();

            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        fseek($handle, $offset);
        $text = (string) fread($handle, min($maxBytes, $size - $offset));
        fclose($handle);

        // A first read from the middle of a file starts on a partial line.
        if (! $cursor->exists && $offset > 0) {
            $firstNewline = strpos($text, "\n");

            if ($firstNewline === false) {
                return null;
            }

            $offset += $firstNewline + 1;
            $text = substr($text, $firstNewline + 1);
        }

        // Keep a half-written last line for the next run.
        $lastNewline = strrpos($text, "\n");

        if ($lastNewline === false) {
            $cursor->offset = $offset;
            $cursor->save();

            return null;
        }

        $text = substr($text, 0, $lastNewline + 1);

        return ['text' => $text, 'cursor' => $cursor, 'offset' => $offset + strlen($text)];
    }

    /**
     * @param  array{text: string, cursor: LogCursor, offset: int}  $read
     */
    public function commit(array $read): void
    {
        $read['cursor']->offset = $read['offset'];
        $read['cursor']->save();
    }
}
