<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Changes to files in site folders (and the file manager's trash). When the panel runs as its
 * own user, they run as the site user through sudo, so every file stays owned by www-data and
 * the site can keep writing it. Reading needs no help: the panel user is in the www-data group.
 * On development machines and servers not migrated yet, PHP does the same work directly.
 */
class SiteFiles
{
    public function __construct(private SiteUser $site) {}

    /**
     * Write a file through a temporary file and a rename. A new file gets $mode (or 0644);
     * a replaced file keeps its mode unless $mode is given.
     */
    public function put(string $path, string $contents, ?int $mode = null): void
    {
        if (! $this->site->separate()) {
            $this->putDirectly($path, $contents, $mode);

            return;
        }

        $this->run(['/bin/sh', '-c', self::PUT_SCRIPT, 'put', $path, $mode === null ? '' : sprintf('%04o', $mode)], $contents, 'Could not write '.basename($path).'.');
    }

    /**
     * Write a file from a file the panel can read (an upload, a zip it made).
     */
    public function putFile(string $path, string $source, ?int $mode = null): void
    {
        if (! $this->site->separate()) {
            if (is_link($path) && ! @unlink($path)) {
                throw new RuntimeException('Could not replace '.basename($path).'.');
            }

            if (! @copy($source, $path)) {
                throw new RuntimeException('Could not write '.basename($path).'.');
            }

            $mode === null || @chmod($path, $mode);

            return;
        }

        $stream = @fopen($source, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Could not read '.basename($source).'.');
        }

        try {
            $this->run(['/bin/sh', '-c', self::PUT_SCRIPT, 'put', $path, $mode === null ? '' : sprintf('%04o', $mode)], $stream, 'Could not write '.basename($path).'.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false): void
    {
        if (! $this->site->separate()) {
            if (! is_dir($path) && ! @mkdir($path, $mode, $recursive) && ! is_dir($path)) {
                throw new RuntimeException('Could not create the folder '.basename($path).'.');
            }

            return;
        }

        $this->run(array_values(array_filter(['mkdir', $recursive ? '-p' : null, '-m', sprintf('%04o', $mode), '--', $path])), null, 'Could not create the folder '.basename($path).'.');
    }

    /**
     * Rename or move a file or folder. Never replaces an existing folder.
     */
    public function move(string $from, string $to): void
    {
        if (! $this->site->separate()) {
            if (@rename($from, $to)) {
                return;
            }

            if (is_dir($from) && ! is_link($from)) {
                if (! File::copyDirectory($from, $to)) {
                    throw new RuntimeException('Could not move '.basename($from).'.');
                }

                File::deleteDirectory($from);

                return;
            }

            if (! @copy($from, $to) || ! @unlink($from)) {
                throw new RuntimeException('Could not move '.basename($from).'.');
            }

            return;
        }

        $this->run(['mv', '-T', '--', $from, $to], null, 'Could not move '.basename($from).'.');
    }

    /**
     * Copy a file or folder with everything inside it. Links are copied as links.
     */
    public function copy(string $from, string $to): void
    {
        if (! $this->site->separate()) {
            if (is_dir($from) && ! is_link($from)) {
                if (! File::copyDirectory($from, $to)) {
                    throw new RuntimeException('Could not copy '.basename($from).'.');
                }

                return;
            }

            if (! @copy($from, $to)) {
                throw new RuntimeException('Could not copy '.basename($from).'.');
            }

            @chmod($to, @fileperms($from) & 0777);

            return;
        }

        $this->run(['cp', '-R', '-P', '--preserve=mode,timestamps', '-T', '--', $from, $to], null, 'Could not copy '.basename($from).'.');
    }

    /**
     * Delete a file, a link (not what it points to), or a folder with everything inside it.
     */
    public function delete(string $path): void
    {
        if (! $this->site->separate()) {
            if (is_link($path) || is_file($path)) {
                if (! @unlink($path)) {
                    throw new RuntimeException('Could not delete '.basename($path).'.');
                }

                return;
            }

            if (is_dir($path) && ! File::deleteDirectory($path)) {
                throw new RuntimeException('Could not delete '.basename($path).'.');
            }

            return;
        }

        $this->run(['rm', '-rf', '--', $path], null, 'Could not delete '.basename($path).'.');
    }

    public function chmod(string $path, int $mode): void
    {
        if (! $this->site->separate()) {
            if (! @chmod($path, $mode)) {
                throw new RuntimeException('Could not change the permissions of '.basename($path).'.');
            }

            return;
        }

        $this->run(['chmod', sprintf('%04o', $mode), '--', $path], null, 'Could not change the permissions of '.basename($path).'.');
    }

    /**
     * Point $link at $target, replacing a link or file that is already there.
     */
    public function symlink(string $target, string $link): void
    {
        if (! $this->site->separate()) {
            if ((is_link($link) || is_file($link)) && ! @unlink($link)) {
                throw new RuntimeException('Could not replace '.basename($link).'.');
            }

            if (! @symlink($target, $link)) {
                throw new RuntimeException('Could not link '.basename($link).' to '.$target.'.');
            }

            return;
        }

        $this->run(['ln', '-sfnT', '--', $target, $link], null, 'Could not link '.basename($link).' to '.$target.'.');
    }

    /**
     * Run a command as the site user, for unpacking archives into site folders.
     *
     * @param  list<string>  $command
     * @param  resource|string|null  $input
     */
    public function exec(array $command, $input = null, ?string $cwd = null): CommandResult
    {
        $argv = $this->site->separate() ? $this->site->wrap($command, ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8']) : $command;
        $process = new Process($argv, $cwd, null, $input, 3600);
        $process->run();

        return new CommandResult(
            $process->isSuccessful(),
            false,
            $command,
            trim($process->getOutput()."\n".$process->getErrorOutput()),
            $process->getExitCode() ?? 1,
        );
    }

    /**
     * Run a command as the site user and save what it prints to $outputFile (a panel file), like
     * `tar -czf - folder` for a backup that must read every file of the sites.
     *
     * @param  list<string>  $command
     */
    public function execToFile(array $command, string $outputFile, ?string $cwd = null): CommandResult
    {
        $handle = @fopen($outputFile, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Could not write '.basename($outputFile).'.');
        }

        $argv = $this->site->separate() ? $this->site->wrap($command, ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8']) : $command;
        $errors = '';

        try {
            $process = new Process($argv, $cwd, null, null, 14400);
            $process->run(function (string $type, string $buffer) use ($handle, &$errors): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                } elseif (strlen($errors) < 20000) {
                    $errors .= $buffer;
                }
            });
        } finally {
            fclose($handle);
        }

        return new CommandResult($process->isSuccessful(), false, $command, trim($errors), $process->getExitCode() ?? 1);
    }

    /**
     * The temp file sits next to the target, so the rename stays on one disk. A replaced file
     * keeps its mode; $2 sets one.
     */
    private const PUT_SCRIPT = <<<'SH'
set -e
target="$1"
temporary="${target}.laravelify-$$"
trap 'rm -f -- "$temporary"' EXIT
cat > "$temporary"
if [ -n "$2" ]; then
  chmod "$2" -- "$temporary"
elif [ -f "$target" ] && [ ! -L "$target" ]; then
  chmod --reference="$target" -- "$temporary"
fi
mv -f -T -- "$temporary" "$target"
trap - EXIT
SH;

    /**
     * @param  list<string>  $command
     * @param  resource|string|null  $input
     */
    private function run(array $command, $input, string $error): void
    {
        $result = $this->exec($command, $input);

        if (! $result->ok) {
            throw new RuntimeException($error.($result->output !== '' ? ' '.$result->output : ''));
        }
    }

    private function putDirectly(string $path, string $contents, ?int $mode): void
    {
        $existing = is_file($path) && ! is_link($path) ? @fileperms($path) & 0777 : null;
        $temporary = $path.'.laravelify-'.bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException('Could not write '.basename($path).'.');
        }

        $mode ??= $existing;
        $mode === null || @chmod($temporary, $mode);

        if (! @rename($temporary, $path)) {
            // Windows cannot rename over an open file; write in place instead.
            @unlink($temporary);

            if (@file_put_contents($path, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Could not write '.basename($path).'.');
            }
        }
    }
}
