<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Connect this panel to Laravelify Cloud, which installed it: websites can then use free domains
 * like john-shop.laravelify.cloud, created by Cloud on this server's tunnel. The panel only gets
 * a token for its own server (from LARAVELIFY_CLOUD_TOKEN, so it never shows in the process list),
 * never a Cloudflare key.
 */
class ConnectCloud extends Command
{
    protected $signature = 'laravelify:cloud-connect {api : Laravelify Cloud\'s panel API address} {--prefix= : The client name free domains start with} {--domain= : The domain free domains end with}';

    protected $description = 'Connect the panel to Laravelify Cloud for free domains (token in LARAVELIFY_CLOUD_TOKEN)';

    public function handle(): int
    {
        $token = trim((string) getenv('LARAVELIFY_CLOUD_TOKEN'));
        $api = rtrim((string) $this->argument('api'), '/');
        $prefix = strtolower(trim((string) $this->option('prefix')));
        $domain = strtolower(trim((string) $this->option('domain')));

        if ($token === '' || ! str_starts_with($api, 'https://') || ! preg_match('/^[a-z0-9-]{3,40}$/', $prefix) || ! preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            $this->error('Give LARAVELIFY_CLOUD_TOKEN, an https API address, --prefix and --domain.');

            return self::FAILURE;
        }

        Setting::putValue('cloud_api', $api);
        Setting::putValue('cloud_token', $token);
        Setting::putValue('cloud_prefix', $prefix);
        Setting::putValue('cloud_domain', $domain);

        $this->line("Free domains are on: {$prefix}-<name>.{$domain}");

        return self::SUCCESS;
    }
}
