<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tell Laravelify Cloud which Laravelify version this panel runs, so the server's card there can
 * show it and offer an update. Only for panels linked to Cloud (cloud_api and cloud_token); sends
 * the version and nothing else. Run hourly and after every update.
 */
class ReportToCloud extends Command
{
    protected $signature = 'laravelify:cloud-report';

    protected $description = 'Send this panel\'s Laravelify version to Laravelify Cloud';

    public function handle(): int
    {
        $api = (string) Setting::getValue('cloud_api');
        $token = (string) Setting::getValue('cloud_token');
        $version = trim((string) @file_get_contents(base_path('VERSION')));

        if ($api === '' || $token === '' || ! str_starts_with($api, 'https://') || $version === '') {
            return self::SUCCESS;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->withToken($token)->post(rtrim($api, '/').'/version', ['version' => $version]);
        } catch (Throwable $exception) {
            $this->warn('Laravelify Cloud could not be reached: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->warn('Laravelify Cloud did not take the version (HTTP '.$response->status().').');

            return self::FAILURE;
        }

        $this->line("Laravelify Cloud knows this panel runs {$version}.");

        return self::SUCCESS;
    }
}
