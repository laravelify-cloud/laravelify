<?php

namespace App\Mcp\Concerns;

use App\Support\EnvFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The `env` parameter of the MCP tools: "KEY=value" strings, checked before anything is saved.
 */
trait ReadsEnvValues
{
    /**
     * @param  array<int, mixed>  $lines  "KEY=value" strings
     * @return array<string, string>
     */
    protected function envValues(array $lines): array
    {
        $values = [];

        foreach ($lines as $line) {
            [$key, $value] = array_pad(explode('=', (string) $line, 2), 2, null);
            $key = trim((string) $key);

            if ($value === null || ! EnvFile::validKey($key)) {
                throw ValidationException::withMessages(['env' => 'Write each variable as KEY=value ("'.$line.'" is not).']);
            }

            $values[$key] = $value;
        }

        try {
            EnvFile::set('', $values);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['env' => $exception->getMessage()]);
        }

        return $values;
    }
}
