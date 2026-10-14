<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class CommandRunner
{
    private $shouldStop = null;

    public function stopWhen(?callable $callback): void
    {
        $this->shouldStop = $callback;
    }

    public function supportsHostCommands(): bool
    {
        $override = config('laravelify.host_commands');

        if ($override !== null && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return PHP_OS_FAMILY === 'Linux';
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  Extra variables for this command only, such as storage keys.
     * @param  (callable(string): void)|null  $onOutput  Called with each piece of output while the command runs.
     */
    public function run(array $command, ?string $cwd = null, array $env = [], ?callable $onOutput = null): CommandResult
    {
        if ($this->stopped()) {
            return new CommandResult(false, false, $command, 'Stopped.', 130, true);
        }

        if (! $this->supportsHostCommands()) {
            return new CommandResult(true, true, $command, '[skipped] host commands are disabled', 0);
        }

        $process = new Process($command, $cwd, array_merge($this->environment(), $env), null, 3600);
        $process->start($onOutput === null ? null : function (string $type, string $buffer) use ($onOutput): void {
            $onOutput($buffer);
        });

        while ($process->isRunning()) {
            if ($this->stopped()) {
                $this->halt($process);

                return new CommandResult(
                    false,
                    false,
                    $command,
                    trim($process->getOutput()."\n".$process->getErrorOutput()."\nStopped."),
                    130,
                    true,
                );
            }

            usleep(250000);
        }

        return new CommandResult(
            $process->isSuccessful(),
            false,
            $command,
            trim($process->getOutput()."\n".$process->getErrorOutput()),
            $process->getExitCode() ?? 1,
        );
    }

    private function stopped(): bool
    {
        return is_callable($this->shouldStop) && ($this->shouldStop)();
    }

    private function halt(Process $process): void
    {
        $pid = $process->getPid();

        if (is_int($pid) && $pid > 0 && function_exists('posix_kill')) {
            exec('ps -o pid= --ppid '.$pid, $children);
            foreach ($children as $child) {
                $childPid = (int) trim($child);
                if ($childPid > 0) {
                    posix_kill($childPid, SIGTERM);
                }
            }
            posix_kill($pid, SIGTERM);
        }

        $process->stop(2, SIGTERM);
    }

    /**
     * Variables a site command may inherit from the panel. Everything else is dropped.
     */
    private const INHERITED = [
        'PATH', 'LANG', 'LANGUAGE', 'LC_ALL', 'LC_CTYPE', 'TZ', 'TERM', 'USER', 'LOGNAME', 'SHELL', 'TMPDIR',
        // Needed for processes to start on Windows development machines.
        'SYSTEMROOT', 'WINDIR', 'COMSPEC', 'PATHEXT', 'TEMP', 'TMP',
    ];

    /**
     * A clean environment for site commands. The panel's own .env is loaded into
     * its process (DB_DATABASE, APP_KEY, ...), and Laravel never overrides a
     * variable that already exists, so passing those on would make a site's
     * artisan use the panel's database and key. Symfony Process always inherits
     * the parent environment, so every other variable is removed with false.
     *
     * Composer refuses to start when both HOME and COMPOSER_HOME are empty.
     * The queue worker is a service account and often has neither.
     *
     * @return array<string, string|false>
     */
    public function environment(): array
    {
        $env = [];

        foreach ([getenv() ?: [], $_ENV, $_SERVER] as $source) {
            foreach (array_keys($source) as $key) {
                if (is_string($key)) {
                    $env[$key] = false;
                }
            }
        }

        foreach (getenv() ?: [] as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '' && in_array(strtoupper($key), self::INHERITED, true)) {
                $env[$key] = $value;
            }
        }

        if (! collect($env)->contains(fn ($value, $key) => strtoupper((string) $key) === 'PATH' && is_string($value))) {
            $env['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        }

        $home = $this->ensureDirectory(storage_path('app/process-home'));
        $env['HOME'] = $home;
        $env['COMPOSER_HOME'] = $this->ensureDirectory($home.DIRECTORY_SEPARATOR.'.composer');
        $env['NPM_CONFIG_CACHE'] = $this->ensureDirectory($home.DIRECTORY_SEPARATOR.'.npm');
        $env['npm_config_cache'] = $env['NPM_CONFIG_CACHE'];
        $env['NO_COLOR'] = '1';
        // Corepack (pnpm/yarn) must never wait on a "download pnpm?" prompt.
        $env['COREPACK_ENABLE_DOWNLOAD_PROMPT'] = '0';
        $env['COREPACK_HOME'] = $this->ensureDirectory($home.DIRECTORY_SEPARATOR.'.corepack');
        $env['NEXT_TELEMETRY_DISABLED'] = '1';

        return $env;
    }

    /**
     * A writable folder for tool caches. When the panel user cannot create it (on older
     * installs the folder above was made by root), a private temp folder is used instead,
     * so a page never fails because of a cache folder.
     */
    private function ensureDirectory(string $path): string
    {
        if (is_dir($path) || @mkdir($path, 0777, true) || is_dir($path)) {
            @chmod($path, 0777);

            return $path;
        }

        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();
        $fallback = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravelify-'.$user.DIRECTORY_SEPARATOR.basename($path);

        if (! is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }

        return $fallback;
    }
}
