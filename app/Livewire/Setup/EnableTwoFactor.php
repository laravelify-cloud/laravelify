<?php

namespace App\Livewire\Setup;

use App\Services\TwoFactor;
use Livewire\Component;

class EnableTwoFactor extends Component
{
    public string $code = '';

    public string $secret = '';

    public string $qr = '';

    /** "new": the generated key with its QR code; "own": a key the user already uses elsewhere. */
    public string $mode = 'new';

    public string $ownSecret = '';

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

        $this->refreshKey($twoFactor);
    }

    /**
     * Show the generated key again, or make a fresh one.
     */
    public function useNewKey(TwoFactor $twoFactor, bool $fresh = false): void
    {
        $user = auth()->user();

        if ($fresh || $this->mode === 'own') {
            $user->two_factor_secret = $twoFactor->generateSecret();
            $user->save();
        }

        $this->mode = 'new';
        $this->ownSecret = '';
        $this->code = '';
        $this->resetErrorBag();
        $this->refreshKey($twoFactor);
    }

    public function useOwnKey(): void
    {
        $this->mode = 'own';
        $this->code = '';
        $this->resetErrorBag();
    }

    public function confirm(TwoFactor $twoFactor): void
    {
        $this->validate([
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ]);

        $user = auth()->user();

        if ($this->mode === 'own') {
            $secret = $twoFactor->normalizeSecret($this->ownSecret);

            if ($secret === null) {
                $this->addError('ownSecret', 'This key does not look right. Paste the key from your authenticator app or password manager: letters A to Z and numbers 2 to 7, at least '.TwoFactor::MIN_SECRET_LENGTH.' characters. An otpauth:// link works too.');

                return;
            }

            if (! $twoFactor->verifySecret($secret, $this->code)) {
                $this->addError('code', 'That code does not match this key. Enter the current code that your app shows for this key.');

                return;
            }

            $user->two_factor_secret = $secret;
        } elseif (! $twoFactor->verify($user, $this->code)) {
            $this->addError('code', 'That authentication code is not valid.');

            return;
        }

        $user->two_factor_confirmed_at = now();
        $user->save();

        $this->redirect(route('dashboard'));
    }

    private function refreshKey(TwoFactor $twoFactor): void
    {
        $user = auth()->user();
        $this->secret = $user->two_factor_secret;
        $this->qr = $twoFactor->qrSvg($user);
    }

    public function render()
    {
        return view('livewire.setup.enable-two-factor')
            ->layout('layouts.guest')
            ->title('Set up two-factor authentication');
    }
}
