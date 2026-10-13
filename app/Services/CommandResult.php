<?php

namespace App\Services;

class CommandResult
{
    /**
     * @param  list<string>  $command
     */
    public function __construct(
        public bool $ok,
        public bool $skipped,
        public array $command,
        public string $output,
        public int $exitCode,
        public bool $stopped = false,
    ) {}

    public function commandLine(): string
    {
        return implode(' ', array_map(
            fn (string $part) => preg_match('/^[A-Za-z0-9_\\/.:=@+-]+$/', $part) ? $part : escapeshellarg($part),
            $this->command,
        ));
    }
}
