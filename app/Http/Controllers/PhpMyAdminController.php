<?php

namespace App\Http\Controllers;

use App\Models\ServerService;
use App\Models\Setting;
use App\Services\HostWriter;
use App\Services\ServerCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Open phpMyAdmin already signed in. Behind the panel login and two-factor check
 * (PanelGate). Creates a one-time token valid for 60 seconds; only its SHA-256
 * hash is stored, and scripts/phpmyadmin-signon.php exchanges it for a session.
 */
class PhpMyAdminController extends Controller
{
    /** Bump when the sign-in setup changes, so it is installed again. */
    private const SETUP_VERSION = '1';

    public function __invoke(HostWriter $hostWriter): RedirectResponse
    {
        $service = ServerService::query()->where('kind', 'phpmyadmin')->first();
        $domain = trim((string) $service?->domain);

        if (! $service || $domain === '' || $domain === 'phpmyadmin.internal') {
            return $this->back('Set a domain for phpMyAdmin on the Server page first.', $service);
        }

        if (Setting::getValue('phpmyadmin_sso') !== self::SETUP_VERSION) {
            try {
                $service->update(['config' => ServerCatalog::withSignonLocation((string) $service->config)]);
                $hostWriter->writeService($service);
                $hostWriter->phpMyAdminSignon();
                Setting::putValue('phpmyadmin_sso', self::SETUP_VERSION);
            } catch (Throwable $exception) {
                return $this->back('Could not set up one-click sign-in: '.$exception->getMessage(), $service);
            }
        }

        $token = Str::random(64);
        $directory = storage_path('app/pma-sso');
        File::ensureDirectoryExists($directory, 0700);

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < time() - 300) {
                @unlink($file->getPathname());
            }
        }

        $path = $directory.'/'.hash('sha256', $token).'.json';
        File::put($path, json_encode(['expires' => time() + 60, 'user_id' => auth()->id()]));
        @chmod($path, 0600);

        $scheme = filter_var($domain, FILTER_VALIDATE_IP) || ! str_contains($domain, '.') ? 'http' : 'https';

        return redirect()->away($scheme.'://'.$domain.'/laravelify-signon.php?token='.$token);
    }

    private function back(string $message, ?ServerService $service): RedirectResponse
    {
        session()->flash('toast', ['type' => 'error', 'message' => $message]);

        return $service ? redirect()->route('server.edit', $service) : redirect()->route('server.index');
    }
}
