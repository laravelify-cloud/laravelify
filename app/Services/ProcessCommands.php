<?php

namespace App\Services;

use Throwable;

/**
 * PM2 and Supervisor commands typed in the terminal. Terminal commands run as
 * www-data, but site apps live under root's PM2 daemon and Supervisor needs
 * root, so these few commands go through ServerProcesses (sudo host-apply.sh)
 * instead. Anything else returns null and runs as a normal command.
 */
class ProcessCommands
{
    public function __construct(private ServerProcesses $processes) {}

    public function handle(string $command): ?CommandResult
    {
        $command = trim((string) preg_replace('/\s+/', ' ', $command));

        if (preg_match('/^pm2 (list|ls|l|status|jlist)$/', $command) === 1) {
            return $this->table($command, 'pm2');
        }

        if (preg_match('/^supervisorctl status$/', $command) === 1) {
            return $this->table($command, 'supervisor');
        }

        if (preg_match('/^pm2 (restart|reload|delete|del|logs)(?: (\S+))?(?: --\S+)*$/', $command, $match) === 1) {
            return $this->act($command, 'pm2', $match[1], $match[2] ?? '');
        }

        if (preg_match('/^supervisorctl (restart|stop|tail)(?: -f| -\d+)?(?: (\S+))?$/', $command, $match) === 1) {
            return $this->act($command, 'supervisor', $match[1], $match[2] ?? '');
        }

        return null;
    }

    private function table(string $command, string $manager): CommandResult
    {
        try {
            $rows = collect($this->processes->list())->where('manager', $manager)->values();
        } catch (Throwable $exception) {
            return $this->result($command, $exception->getMessage(), false);
        }

        if ($rows->isEmpty()) {
            return $this->result($command, $manager === 'pm2' ? 'No PM2 apps are running.' : 'Supervisor has no programs.', true);
        }

        $width = max(4, $rows->max(fn (array $row): int => strlen($row['name'])));
        $lines = [str_pad('NAME', $width).'  '.str_pad('STATUS', 10).'  '.str_pad('PID', 8).'  DETAIL'];

        foreach ($rows as $row) {
            $lines[] = str_pad($row['name'], $width).'  '.str_pad(strtolower($row['status']), 10).'  '.str_pad($row['pid'] ?: '-', 8).'  '.$row['detail'];
        }

        return $this->result($command, implode("\n", $lines)."\n\n(Run through the panel as root.)", true);
    }

    private function act(string $command, string $manager, string $action, string $name): CommandResult
    {
        if ($name === '') {
            return $this->result($command, 'Add the process name, for example: '.$command.' '.($manager === 'pm2' ? 'my-next-site' : 'my-site-queue').'. List them with '.($manager === 'pm2' ? 'pm2 list' : 'supervisorctl status').'.', false);
        }

        try {
            $output = match ($action) {
                'restart', 'reload' => $this->processes->restart($manager, $name),
                // Supervisor "stop" keeps the program; PM2 "delete" removes the app until the site is saved or deployed again.
                'delete', 'del', 'stop' => $this->processes->delete($manager, $name),
                'logs', 'tail' => $this->processes->logs($manager, $name),
            };
        } catch (Throwable $exception) {
            return $this->result($command, $exception->getMessage(), false);
        }

        return $this->result($command, trim($output), true);
    }

    private function result(string $command, string $output, bool $ok): CommandResult
    {
        return new CommandResult($ok, false, ['panel', $command], $output, $ok ? 0 : 1);
    }
}
