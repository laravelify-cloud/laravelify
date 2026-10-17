<?php

namespace App\Services;

use App\Models\Website;
use RuntimeException;
use Symfony\Component\Process\Process;

class SiteCommand
{
    public const OUTPUT_LIMIT = 200000;

    public function __construct(private CommandRunner $commands) {}

    /**
     * Run a command as www-data inside the site's deploy folder.
     */
    public function run(Website $website, string $command): CommandResult
    {
        $command = $this->clean($command);

        if (! $this->commands->supportsHostCommands()) {
            return new CommandResult(false, true, ['run', $command], 'Host commands are disabled on this machine.', 1);
        }

        return $this->execute(['run', app(SitePaths::class)->root($website), $command]);
    }

    /**
     * Run a command as www-data in a folder of the sites root, for the global terminal.
     */
    public function runGlobal(string $directory, string $command): CommandResult
    {
        $command = $this->clean($command);
        $paths = app(SitePaths::class);
        $sitesRoot = realpath((string) config('laravelify.sites_root'));

        if ($sitesRoot === false || ! is_dir($sitesRoot)) {
            throw new RuntimeException('The sites folder does not exist on this machine.');
        }

        $folder = $paths->resolveIn($sitesRoot, $directory);

        if (! is_dir($folder)) {
            throw new RuntimeException('That folder does not exist.');
        }

        if (! $this->commands->supportsHostCommands()) {
            return new CommandResult(false, true, ['run-global', $command], 'Host commands are disabled on this machine.', 1);
        }

        return $this->execute(['run-global', $folder, $command]);
    }

    /**
     * The command line for an interactive (terminal) run: the same sandbox and folder rules as run()
     * and runGlobal(), with the command getting a real terminal and up to 15 minutes.
     *
     * @return list<string>
     */
    public function interactiveArguments(?Website $website, string $directory, string $command): array
    {
        $command = $this->clean($command);

        if (! $this->commands->supportsHostCommands()) {
            throw new RuntimeException('Host commands are disabled on this machine.');
        }

        if ($website) {
            $arguments = ['run', app(SitePaths::class)->root($website), $command, 'pty'];
        } else {
            $paths = app(SitePaths::class);
            $sitesRoot = realpath((string) config('laravelify.sites_root'));

            if ($sitesRoot === false || ! is_dir($sitesRoot)) {
                throw new RuntimeException('The sites folder does not exist on this machine.');
            }

            $folder = $paths->resolveIn($sitesRoot, $directory);

            if (! is_dir($folder)) {
                throw new RuntimeException('That folder does not exist.');
            }

            $arguments = ['run-global', $folder, $command, 'pty'];
        }

        $argv = [base_path('scripts/host-apply.sh'), ...$arguments];

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            array_unshift($argv, 'sudo', '-n');
        }

        return $argv;
    }

    private function clean(string $command): string
    {
        $command = trim(str_replace("\0", '', $command));

        if ($command === '') {
            throw new RuntimeException('Enter a command.');
        }

        if (strlen($command) > 4000) {
            throw new RuntimeException('That command is too long.');
        }

        return $command;
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $arguments  action, folder, command
     */
    private function execute(array $arguments): CommandResult
    {
        $argv = [base_path('scripts/host-apply.sh'), ...$arguments];

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            array_unshift($argv, 'sudo', '-n');
        }

        $process = new Process($argv, null, null, null, 75);
        $process->start();

        $output = '';
        $truncated = false;

        while ($process->isRunning()) {
            $output .= $process->getIncrementalOutput();
            $output .= $process->getIncrementalErrorOutput();

            if (strlen($output) >= self::OUTPUT_LIMIT) {
                $truncated = true;
                $process->stop(0);
                break;
            }

            usleep(100000);
        }

        $output .= $process->getIncrementalOutput();
        $output .= $process->getIncrementalErrorOutput();
        $output = trim($output);

        if ($truncated || strlen($output) > self::OUTPUT_LIMIT) {
            $output = substr($output, 0, self::OUTPUT_LIMIT)."\n[output truncated]";
        }

        return new CommandResult(
            $truncated || $process->isSuccessful(),
            false,
            $argv,
            $output,
            $truncated ? 0 : ($process->getExitCode() ?? 1),
        );
    }
}
