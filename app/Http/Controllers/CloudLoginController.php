<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\AccessGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * "Open panel" from Laravelify Cloud: the browser arrives with a one-time code, and the panel asks
 * Cloud (with its own server token, never sent to the browser) whether the code was made for it.
 * A good code replaces the password only: the authenticator code is still asked next
 * (Livewire\Auth\CloudLogin). Nothing happens on a panel that is not linked to Cloud.
 */
class CloudLoginController extends Controller
{
    /** How long the checked code waits for the authenticator code. */
    public const PENDING_SECONDS = 300;

    public function __invoke(Request $request, AccessGate $gate): RedirectResponse
    {
        $code = (string) $request->query('code');
        $api = (string) Setting::getValue('cloud_api');
        $token = (string) Setting::getValue('cloud_token');
        $limit = 'cloud-login:'.$gate->clientIp($request);

        if ($api === '' || $token === '' || ! str_starts_with($api, 'https://') || ! preg_match('/^[A-Za-z0-9]{64}$/', $code)) {
            return $this->back('This panel is not linked to Laravelify Cloud. Sign in with your password.');
        }

        if (RateLimiter::tooManyAttempts($limit, 10)) {
            return $this->back('Too many attempts. Try again in '.RateLimiter::availableIn($limit).' seconds.');
        }

        RateLimiter::hit($limit, 300);

        try {
            $answer = Http::timeout(10)->acceptJson()->withToken($token)->post(rtrim($api, '/').'/login', ['code' => $code]);
        } catch (Throwable) {
            return $this->back('Laravelify Cloud could not be reached. Sign in with your password.');
        }

        $user = User::query()->oldest('id')->first();

        if (! $answer->successful() || $answer->json('ok') !== true || ! $user) {
            return $this->back((string) ($answer->json('message') ?: 'This link did not work. Open the panel again from Laravelify Cloud.'));
        }

        $request->session()->regenerate();
        $request->session()->put('cloud_login', ['user_id' => $user->id, 'until' => time() + self::PENDING_SECONDS]);

        return redirect()->route('cloud-login.confirm');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('status', $message);
    }
}
