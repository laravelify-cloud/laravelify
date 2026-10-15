<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Small edits to the text of a .env file: set or remove keys and keep everything else
 * (comments, order, other keys) as it was.
 */
class EnvFile
{
    /**
     * @param  array<string, string|int|float|bool|null>  $values  null removes the key
     */
    public static function set(string $content, array $values): string
    {
        $lines = $content === '' ? [] : (preg_split('/\r\n|\n|\r/', rtrim($content, "\r\n")) ?: []);

        foreach ($values as $key => $value) {
            if (! self::validKey((string) $key)) {
                throw new InvalidArgumentException('"'.$key.'" is not a valid variable name. Use letters, numbers, and underscores, starting with a letter or underscore.');
            }

            $pattern = '/^\s*(export\s+)?'.preg_quote((string) $key, '/').'\s*=/';
            $found = false;

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line) === 1) {
                    if ($value === null) {
                        unset($lines[$index]);
                    } else {
                        $lines[$index] = $key.'='.self::quote($value);
                    }

                    $found = true;
                }
            }

            if (! $found && $value !== null) {
                $lines[] = $key.'='.self::quote($value);
            }
        }

        return implode("\n", array_values($lines))."\n";
    }

    /**
     * @return list<string> The variable names in the file, in order.
     */
    public static function keys(string $content): array
    {
        preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', $content, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The values of some keys, unquoted, as the app reads them. Missing keys are null.
     *
     * @param  list<string>  $keys
     * @return array<string, ?string>
     */
    public static function values(string $content, array $keys): array
    {
        $values = array_fill_keys($keys, null);

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $match) !== 1 || ! array_key_exists($match[1], $values)) {
                continue;
            }

            $value = trim($match[2]);

            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }

            $values[$match[1]] = $value;
        }

        return $values;
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1;
    }

    private static function quote(string|int|float|bool $value): string
    {
        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new InvalidArgumentException('A .env value cannot span several lines.');
        }

        // Plain values stay unquoted, like Laravel writes them (APP_KEY=base64:…=).
        if ($value === '' || preg_match('/^[A-Za-z0-9_.,:\/@+=-]*$/', $value) === 1) {
            return $value;
        }

        // Single quotes are read literally by PHP's and Node's dotenv alike ($ included).
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
