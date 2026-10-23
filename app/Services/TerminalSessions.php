<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * An interactive command for the panel's terminals. The command runs in the same sandbox as a plain
 * terminal command, but with a real terminal, so a question it asks (Proceed? Y/n) can be answered.
 * A detached runner (`terminal:run`) keeps the command alive between requests; the browser reads
 * its output from a file by offset and writes what the person types to another file.
 */
class TerminalSessions
{
    private const ID_PATTERN = '/^[A-Za-z0-9]{32}$/';

    private const CHUNK = 262144;

    public function __construct(private SiteCommand $commands) {}

    /**
     * Start a command and return the id of its session.
     *
     * @param  int|string|null  $userId  Who started it; only they can read or write it.
     */
    public function start(?Website $website, string $directory, string $command, int|string|null $userId, int $columns = 120, int $rows = 30, bool $shell = false): string
    {
        if (! Process::isPtySupported()) {
            throw new RuntimeException('This machine cannot give commands a terminal.');
        }

        $columns = max(20, min($columns, 400));
        $rows = max(5, min($rows, 200));
        $argv = $this->commands->interactiveArguments($website, $directory, $this->prepared($command, $columns, $rows), $shell);
        $id = Str::random(32);
        $folder = $this->folder($id);

        File::ensureDirectoryExists($folder, 0700);
        File::put($folder.'/meta.json', (string) json_encode(['argv' => $argv, 'user' => $userId, 'command' => $command, 'started' => time(), 'shell' => $shell]));
        File::put($folder.'/out', '');
        File::put($folder.'/in', '');

        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        Process::fromShellCommandline(sprintf('nohup setsid %s %s terminal:run %s > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg(base_path('artisan')), $id))->run();

        return $id;
    }

    /**
     * Output since an offset: the bytes (base64), the next offset, and the exit code once it ended.
     *
     * @return array{data: string, offset: int, done: bool, exit: ?int}|null
     */
    public function read(string $id, int $offset, int|string|null $userId): ?array
    {
        if (! $this->owns($id, $userId)) {
            return null;
        }

        $folder = $this->folder($id);
        $handle = fopen($folder.'/out', 'rb');
        $chunk = '';

        if ($handle !== false) {
            fseek($handle, max(0, $offset));
            $chunk = (string) fread($handle, self::CHUNK);
            fclose($handle);
        }

        $exit = is_file($folder.'/exit') ? (int) trim((string) File::get($folder.'/exit')) : null;
        $next = max(0, $offset) + strlen($chunk);
        $caughtUp = $exit !== null && $next >= (int) filesize($folder.'/out');

        return ['data' => base64_encode($chunk), 'offset' => $next, 'done' => $caughtUp, 'exit' => $caughtUp ? $exit : null];
    }

    public function write(string $id, string $data, int|string|null $userId): bool
    {
        if (! $this->owns($id, $userId) || is_file($this->folder($id).'/exit')) {
            return false;
        }

        File::append($this->folder($id).'/in', substr($data, 0, 65536));

        return true;
    }

    public function stop(string $id, int|string|null $userId): bool
    {
        if (! $this->owns($id, $userId)) {
            return false;
        }

        File::put($this->folder($id).'/stop', '1');

        return true;
    }

    /**
     * Forget sessions started more than a day ago.
     */
    public function prune(): void
    {
        $root = storage_path('app/terminal');

        if (! is_dir($root)) {
            return;
        }

        foreach (File::directories($root) as $folder) {
            if (filemtime($folder) < time() - 86400) {
                File::deleteDirectory($folder);
            }
        }
    }

    public function folder(string $id): string
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new RuntimeException('That is not a terminal session.');
        }

        return storage_path('app/terminal/'.$id);
    }

    private function owns(string $id, int|string|null $userId): bool
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1 || ! is_file($this->folder($id).'/meta.json')) {
            return false;
        }

        $meta = json_decode((string) File::get($this->folder($id).'/meta.json'), true);

        return is_array($meta) && (string) ($meta['user'] ?? '') === (string) $userId;
    }

    /**
     * The command with the size of the terminal on screen, so full-screen output lines up.
     */
    private function prepared(string $command, int $columns, int $rows): string
    {
        return sprintf('export TERM=xterm-256color COLUMNS=%d LINES=%d; stty cols %d rows %d 2>/dev/null; %s', $columns, $rows, $columns, $rows, $command);
    }
}
