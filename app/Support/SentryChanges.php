<?php

namespace App\Support;

use RuntimeException;

/**
 * The files SentrySetup changes in one checkout: read once, written only when they changed, and
 * left alone when they already mention Sentry (the setup was added before, by hand or by us).
 */
class SentryChanges
{
    /** @var array<string, ?string> */
    private array $cache = [];

    /** @var list<string> */
    private array $written = [];

    /**
     * @param  callable(string): ?string  $read  A file's contents, or null when it does not exist.
     * @param  callable(string, string): void  $write
     * @param  callable(string): bool  $exists  Whether a file or folder exists.
     */
    public function __construct(private $read, private $write, private $exists) {}

    public function read(string $file): ?string
    {
        if (! array_key_exists($file, $this->cache)) {
            $this->cache[$file] = ($this->read)($file);
        }

        return $this->cache[$file];
    }

    public function exists(string $path): bool
    {
        return isset($this->cache[$path]) || ($this->exists)($path);
    }

    /**
     * Change a file. Skipped when it already contains $unless (sentry by default, in any case).
     *
     * @param  callable(string): string  $change
     */
    public function edit(string $file, callable $change, bool $create = false, string $unless = 'Sentry'): void
    {
        $code = $this->read($file);

        if ($code === null && ! $create) {
            throw new RuntimeException('Could not find '.$file.'. Follow the steps in the GlitchTip tab by hand.');
        }

        if ($code !== null && stripos($code, $unless) !== false) {
            return;
        }

        $this->put($file, $change($code ?? ''));
    }

    /** Replace every match of a pattern in a file, when the file exists and something changes. */
    public function replace(string $file, string $pattern, string $replacement): void
    {
        $code = $this->read($file);

        if ($code === null) {
            return;
        }

        $changed = (string) preg_replace_callback($pattern, fn () => $replacement, $code);

        if ($changed !== $code) {
            $this->put($file, $changed);
        }
    }

    /** Make a new file. One that already exists is used as it is when it sets up Sentry. */
    public function create(string $file, string $contents): void
    {
        $code = $this->read($file);

        if ($code !== null) {
            if (stripos($code, 'sentry') !== false) {
                return;
            }

            throw new RuntimeException($file.' already exists. Add the Sentry lines from the GlitchTip tab to it by hand.');
        }

        $this->put($file, $contents);
    }

    /** @return list<string> */
    public function written(): array
    {
        return $this->written;
    }

    private function put(string $file, string $contents): void
    {
        $contents = rtrim($contents)."\n";
        ($this->write)($file, $contents);
        $this->cache[$file] = $contents;
        $this->written[] = $file;
    }
}
