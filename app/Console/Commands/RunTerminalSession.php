<?php

namespace App\Console\Commands;

use App\Services\TerminalSessions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

class RunTerminalSession extends Command
{
    protected $signature = 'terminal:run {id}';

    protected $description = 'Run the command of an interactive terminal session and keep its output and input in files';

    public function handle(TerminalSessions $sessions): int
    {
        $folder = $sessions->folder((string) $this->argument('id'));
        $meta = json_decode((string) File::get($folder.'/meta.json'), true);

        if (! is_array($meta) || ! is_array($meta['argv'] ?? null)) {
            return self::FAILURE;
        }

        $input = new InputStream;
        $process = new Process($meta['argv'], null, null, null, 960);
        $process->setPty(true);
        $process->setInput($input);
        $process->start();

        $read = 0;

        while ($process->isRunning()) {
            File::append($folder.'/out', $process->getIncrementalOutput().$process->getIncrementalErrorOutput());

            $typed = (string) file_get_contents($folder.'/in', false, null, $read);

            if ($typed !== '') {
                $read += strlen($typed);
                $input->write($typed);
            }

            if (is_file($folder.'/stop')) {
                $this->interrupt($process, $input, $folder);
                File::append($folder.'/out', "\r\n[stopped]\r\n");
                break;
            }

            usleep(50000);
        }

        File::append($folder.'/out', $process->getIncrementalOutput().$process->getIncrementalErrorOutput());
        $input->close();
        File::put($folder.'/exit', (string) ($process->getExitCode() ?? 130));

        return self::SUCCESS;
    }

    /**
     * Stop like Ctrl+C does: type it into the terminal, so the command in the sandbox ends too, and
     * only end the process itself when the command does not stop.
     */
    private function interrupt(Process $process, InputStream $input, string $folder): void
    {
        for ($attempt = 0; $attempt < 2 && $process->isRunning(); $attempt++) {
            $input->write("\x03");

            for ($wait = 0; $wait < 40 && $process->isRunning(); $wait++) {
                File::append($folder.'/out', $process->getIncrementalOutput().$process->getIncrementalErrorOutput());
                usleep(50000);
            }
        }

        if ($process->isRunning()) {
            $process->stop(3);
        }
    }
}
