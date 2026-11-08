<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\AccessGate;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

/**
 * The second step of "Open panel" from Laravelify Cloud: Cloud vouched for the browser in place
 * of the password (CloudLoginController), and the authenticator code is still asked here.
 */
class CloudLogin extends Component
{
    private const MAX_ATTEMPTS = 5;

    public string $code = '';

    public function mount(): void
    {
        if ($this->pending() === null) {
            $this->redirectRoute('login');
        }
    }

    public function confirm(TwoFactor $twoFactor, AccessGate $gate): void
    {
        $user = $this->pending();
        $key = 'cloud-login-code:'.$gate->clientIp(request());

        if ($user === null) {
            $this->redirectRoute('login');

            return;
        }

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            session()->forget('cloud_login');
            $this->addError('code', 'Too many attempts. Open the panel again from Laravelify Cloud in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if ($user->hasConfirmedTwoFactor() && ! $twoFactor->verify($user, $this->code)) {
            RateLimiter::hit($key, 300);
            $this->code = '';
            $this->addError('code', 'The authentication code is invalid.');

            return;
        }

        RateLimiter::clear($key);
        session()->forget('cloud_login');
        Auth::login($user);
        session()->regenerate();

        $this->redirect($user->hasConfirmedTwoFactor() ? route('dashboard') : route('setup.two-factor'));
    }

    private function pending(): ?User
    {
        $pending = session('cloud_login');

        if (! is_array($pending) || ($pending['until'] ?? 0) < time()) {
            return null;
        }

        return User::query()->find($pending['user_id'] ?? 0);
    }

    public function render()
    {
        return view('livewire.auth.cloud-login')
            ->layout('layouts.guest')
            ->title('Log in from Laravelify Cloud');
    }
}
