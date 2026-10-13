<?php

namespace App\Livewire\Auth;

use App\Services\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public string $code = '';

    public function authenticate(TwoFactor $twoFactor): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string'],
        ]);

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            $this->addError('email', 'These credentials do not match.');

            return;
        }

        $user = Auth::user();

        if ($user->hasConfirmedTwoFactor() && ! $twoFactor->verify($user, $this->code)) {
            Auth::logout();
            $this->addError('code', 'The authentication code is invalid.');

            return;
        }

        session()->regenerate();

        $this->redirect($user->hasConfirmedTwoFactor()
            ? route('dashboard')
            : route('setup.two-factor'));
    }

    public function render()
    {
        return view('livewire.auth.login')
            ->layout('layouts.guest')
            ->title('Log in');
    }
}
