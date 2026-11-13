<?php

namespace App\Console\Commands;

use App\Models\CloudflareTunnel;
use App\Models\Setting;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use Illuminate\Console\Command;
use Throwable;

/**
 * Give the panel's own address its route on the Cloudflare tunnel (Routes → Published application,
 * http://localhost:80) and its DNS record. A tunnel token cannot do this, so it needs a Cloudflare
 * API token: the one in --token-file (saved to Settings → Cloudflare too) or the one saved there.
 * Run by the installer; safe to run again.
 */
class PanelRoute extends Command
{
    protected $signature = 'laravelify:panel-route {--token-file= : A file holding a Cloudflare API token to save first}';

    protected $description = "Add the panel's address to its Cloudflare tunnel and DNS";

    public function handle(CloudflareApi $api, CloudflareRoutes $routes): int
    {
        $file = (string) $this->option('token-file');

        if ($file !== '' && is_readable($file)) {
            $token = trim((string) file_get_contents($file));

            if ($token !== '') {
                if (preg_match('/^[A-Za-z0-9_-]{20,500}$/', $token) !== 1) {
                    $this->warn('That does not look like a Cloudflare API token, so it was not saved.');

                    return self::FAILURE;
                }

                Setting::putValue(CloudflareApi::SETTING, $token);
            }
        }

        if (! $api->configured()) {
            $this->line('No Cloudflare API token: add the panel route in Cloudflare yourself.');

            return self::FAILURE;
        }

        $panel = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $tunnel = CloudflareTunnel::query()->where('slug', strtolower(str_replace('.', '-', $panel)))->first()
            ?? CloudflareTunnel::query()->oldest('id')->first();

        if ($panel === '' || ! $tunnel) {
            $this->warn('The panel address or its tunnel was not found.');

            return self::FAILURE;
        }

        try {
            $result = $routes->route($tunnel, $panel, 'http://127.0.0.1:80', null);
        } catch (Throwable $exception) {
            $this->warn('Cloudflare: '.$exception->getMessage());

            return self::FAILURE;
        }

        $ok = $result['lines'] !== [] && collect($result['lines'])->every(fn (array $line): bool => $line['ok']);

        foreach ($result['lines'] as $line) {
            $this->line("{$line['host']}: route {$line['route']}, DNS {$line['dns']}");
        }

        if ($result['note']) {
            $this->warn($result['note']);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
