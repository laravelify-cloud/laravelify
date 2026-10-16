<?php

namespace App\Services;

use App\Models\CryptoMethod;
use App\Models\EncryptedColumn;
use RuntimeException;
use Throwable;

/**
 * Runs a website's encryption method: its PHP code, in the website's folder, as the website's user
 * (the site terminal's sandbox, 60 seconds at most), with its Laravel app loaded when asked. Several
 * values go through one run. The panel never sees the website's keys; it only gets the results.
 */
class CryptoMethods
{
    /** The sandbox takes commands up to 4000 characters; values are sent in batches below that. */
    private const MAX_COMMAND = 3800;

    public function __construct(private SiteCommand $commands) {}

    public function encrypt(CryptoMethod $method, string $text): string
    {
        $result = $this->run($method, (string) $method->encrypt_code, [$text])[0];

        if ($result === null || $result === '') {
            throw new RuntimeException('The method "'.$method->name.'" gave nothing back for that text.');
        }

        return $result;
    }

    /**
     * A search term encrypted for every column marked encrypted in these databases, by
     * "database.table.column", so a search finds the stored (encrypted) values. One run per method;
     * a method that fails is left out.
     *
     * @param  list<string>  $databases
     * @return array<string, string>
     */
    public function termsForEncryptedColumns(string $term, array $databases): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $columns = EncryptedColumn::query()->with('method.website')
            ->when($databases !== [], fn ($query) => $query->whereIn('database', $databases))
            ->get()
            ->filter(fn (EncryptedColumn $column): bool => $column->method !== null);
        $encrypted = [];

        foreach ($columns->groupBy('crypto_method_id') as $sameMethod) {
            try {
                $value = $this->encrypt($sameMethod->first()->method, $term);
            } catch (Throwable) {
                continue;
            }

            foreach ($sameMethod as $column) {
                $encrypted[$column->getAttribute('database').'.'.$column->getAttribute('table').'.'.$column->getAttribute('column')] = $value;
            }
        }

        return $encrypted;
    }

    /**
     * Decrypt values; a value the method cannot decrypt comes back as null.
     *
     * @param  list<string>  $values
     * @return list<?string>
     */
    public function decrypt(CryptoMethod $method, array $values): array
    {
        if (! $method->canDecrypt()) {
            throw new RuntimeException('The method "'.$method->name.'" has no decrypt code.');
        }

        return $this->run($method, (string) $method->decrypt_code, $values);
    }

    /**
     * @param  list<string>  $values
     * @return list<?string>
     */
    public function run(CryptoMethod $method, string $code, array $values): array
    {
        $website = $method->website ?? throw new RuntimeException('The website of this method is gone.');

        if (! $website->isPhp()) {
            throw new RuntimeException('Encryption methods run PHP, so they need a PHP or Laravel website.');
        }

        if (trim($code) === '') {
            throw new RuntimeException('The method has no code.');
        }

        $results = [];

        foreach ($this->batches($method, $code, array_values($values)) as $batch) {
            $command = $this->command($method, $code, $batch);
            $ran = $this->commands->run($website, $command);
            $output = $ran->output;

            if (preg_match('/__LFY__([A-Za-z0-9+\/=]*)__LFY__/', $output, $match) !== 1) {
                throw new RuntimeException('The method did not finish: '.$this->firstLines($output));
            }

            $decoded = json_decode((string) base64_decode($match[1]), true);

            if (! is_array($decoded)) {
                throw new RuntimeException('The method gave an answer the panel cannot read.');
            }

            foreach ($decoded as $item) {
                if (! ($item['ok'] ?? false)) {
                    throw new RuntimeException('The method failed: '.($item['error'] ?? 'unknown error'));
                }

                $results[] = isset($item['value']) ? (string) $item['value'] : null;
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $values
     * @return list<list<string>>
     */
    private function batches(CryptoMethod $method, string $code, array $values): array
    {
        $batches = [];
        $batch = [];

        foreach ($values as $value) {
            if (strlen($this->command($method, $code, [$value])) > self::MAX_COMMAND) {
                throw new RuntimeException('The code and a value are too long to send to the website together. Keep the code short (call a helper of the site).');
            }

            if ($batch !== [] && strlen($this->command($method, $code, [...$batch, $value])) > self::MAX_COMMAND) {
                $batches[] = $batch;
                $batch = [];
            }

            $batch[] = $value;
        }

        return $batch === [] && $batches === [] ? [[]] : [...$batches, ...($batch === [] ? [] : [$batch])];
    }

    /**
     * One shell command: PHP reads the values, loads the site's app if asked, and prints the results
     * between markers (base64, so nothing the code prints can confuse them).
     *
     * @param  list<string>  $values
     */
    private function command(CryptoMethod $method, string $code, array $values): string
    {
        $boot = $method->boot_laravel
            ? "require 'vendor/autoload.php'; \$lfyApp = require 'bootstrap/app.php'; \$lfyApp->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
            : "if (is_file('vendor/autoload.php')) { require 'vendor/autoload.php'; }";

        $script = 'error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);'
            ."\$lfyIn = json_decode(base64_decode('".base64_encode((string) json_encode($values))."'), true);"
            .$boot
            .'$lfyMethod = function (string $text) { '.rtrim(trim($code), ';').'; };'
            .'$lfyOut = [];'
            .'foreach ($lfyIn as $lfyText) { try { $lfyValue = $lfyMethod((string) $lfyText); $lfyOut[] = ["ok" => true, "value" => ($lfyValue === false || $lfyValue === null) ? null : (is_scalar($lfyValue) ? (string) $lfyValue : json_encode($lfyValue))]; } catch (\Throwable $lfyError) { $lfyOut[] = ["ok" => false, "error" => $lfyError->getMessage()]; } }'
            .'echo "\n__LFY__".base64_encode(json_encode($lfyOut))."__LFY__\n";';

        $php = 'php'.(preg_match('/^\d+\.\d+$/', (string) $method->website?->php_version) === 1 ? $method->website->php_version : '');

        return $php.' -r "eval(base64_decode(\''.base64_encode($script).'\'));"';
    }

    private function firstLines(string $output): string
    {
        $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $output)))), 0, 3);

        return $lines === [] ? 'no output' : mb_substr(implode(' ', $lines), 0, 300);
    }
}
