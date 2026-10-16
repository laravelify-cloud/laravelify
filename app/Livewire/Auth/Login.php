<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\AccessGate;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

class Login extends Component
{
    /** Wrong answers allowed per email and IP, and per IP, before a pause. */
    private const MAX_PER_ACCOUNT = 5;

    private const MAX_PER_IP = 20;

    private const LOCK_SECONDS = 300;

    public string $email = '';

    public string $password = '';

    public string $code = '';

    public function authenticate(TwoFactor $twoFactor, AccessGate $gate): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        $ip = $gate->clientIp(request());
        $accountKey = 'login:'.Str::lower($this->email).'|'.$ip;
        $ipKey = 'login-ip:'.$ip;

        foreach ([[$accountKey, self::MAX_PER_ACCOUNT], [$ipKey, self::MAX_PER_IP]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $this->addError('email', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

                return;
            }
        }

        // Check the password and the code before signing in, so a wrong code never opens a session.
        $user = User::query()->where('email', $this->email)->first();
        $passwordOk = $user !== null && Auth::getProvider()->validateCredentials($user, ['password' => $this->password]);

        if (! $passwordOk) {
            $this->failed($accountKey, $ipKey);
            $this->addError('email', 'These credentials do not match.');

            return;
        }

        if ($user->hasConfirmedTwoFactor() && ! $twoFactor->verify($user, $this->code)) {
            $this->failed($accountKey, $ipKey);
            $this->addError('code', 'The authentication code is invalid.');

            return;
        }

        RateLimiter::clear($accountKey);
        Auth::login($user);
        session()->regenerate();

        $this->redirect($user->hasConfirmedTwoFactor()
            ? route('dashboard')
            : route('setup.two-factor'));
    }

    private function failed(string $accountKey, string $ipKey): void
    {
        RateLimiter::hit($accountKey, self::LOCK_SECONDS);
        RateLimiter::hit($ipKey, self::LOCK_SECONDS);
        $this->password = '';
        $this->code = '';
    }

    public function render()
    {
        return view('livewire.auth.login')
            ->layout('layouts.guest')
            ->title('Log in');
    }
}
