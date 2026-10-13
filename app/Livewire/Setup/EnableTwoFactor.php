<?php

namespace App\Livewire\Setup;

use App\Services\TwoFactor;
use Livewire\Component;

class EnableTwoFactor extends Component
{
    public string $code = '';

    public string $secret = '';

    public string $qr = '';

    public function mount(TwoFactor $twoFactor): void
    {
        $user = auth()->user();

        abort_unless($user, 403);

        if ($user->hasConfirmedTwoFactor()) {
            $this->redirect(route('dashboard'));

            return;
        }

        if (! $user->two_factor_secret) {
            $user->two_factor_secret = $twoFactor->generateSecret();
            $user->save();
        }

        $this->secret = $user->two_factor_secret;
        $this->qr = $twoFactor->qrSvg($user);
    }

    public function confirm(TwoFactor $twoFactor): void
    {
        $this->validate([
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ]);

        $user = auth()->user();

        if (! $twoFactor->verify($user, $this->code)) {
            $this->addError('code', 'That authentication code is not valid.');

            return;
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->redirect(route('dashboard'));
    }

    public function render()
    {
        return view('livewire.setup.enable-two-factor')
            ->layout('layouts.guest')
            ->title('Set up two-factor authentication');
    }
}
