<?php

namespace App\Livewire\Account;

use App\Livewire\PanelComponent;
use App\Services\TwoFactor;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * The signed-in user's password and authenticator key. Every change is confirmed with the
 * current authenticator code after the form is filled in.
 */
class Security extends PanelComponent
{
    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** The key form: "" closed, "new" a generated key, "own" a key the user already has. */
    public string $keyMode = '';

    #[Locked]
    public string $newSecret = '';

    public string $ownSecret = '';

    public string $newCode = '';

    /** A code from the new key was right, so it only needs the current code to be saved. */
    #[Locked]
    public bool $newKeyChecked = false;

    /**
     * Check the password form. The page then asks for the authenticator code.
     */
    public function checkPassword(): bool
    {
        $this->validatePassword();

        return true;
    }

    public function savePassword(TwoFactor $twoFactor, string $code): bool
    {
        $this->validatePassword();

        if (! $this->confirmCode($twoFactor, $code)) {
            return false;
        }

        $user = auth()->user();
        $user->password = $this->password;
        $user->save();

        $this->reset('currentPassword', 'password', 'password_confirmation');
        $this->toast('Password changed. Use the new one the next time you sign in.');

        return true;
    }

    public function startNewKey(TwoFactor $twoFactor): void
    {
        $this->resetKeyForm();
        $this->keyMode = 'new';
        $this->newSecret = $twoFactor->generateSecret();
    }

    public function startOwnKey(): void
    {
        $this->resetKeyForm();
        $this->keyMode = 'own';
    }

    public function cancelKey(): void
    {
        $this->resetKeyForm();
    }

    /**
     * Check that the authenticator app has the new key: its current code must match.
     * The page then asks for a code from the key in use today.
     */
    public function checkNewKey(TwoFactor $twoFactor): bool
    {
        $this->newKeyChecked = false;
        $this->resetErrorBag();

        if ($this->keyMode === 'own') {
            $secret = $twoFactor->normalizeSecret($this->ownSecret);

            if ($secret === null) {
                $this->addError('ownSecret', 'This key does not look right. Paste the key from your authenticator app or password manager: letters A to Z and numbers 2 to 7, at least '.TwoFactor::MIN_SECRET_LENGTH.' characters. An otpauth:// link works too.');

                return false;
            }

            $this->newSecret = $secret;
        }

        if ($this->newSecret === '') {
            return false;
        }

        if (hash_equals((string) auth()->user()->two_factor_secret, $this->newSecret)) {
            $this->addError($this->keyMode === 'own' ? 'ownSecret' : 'newCode', 'This is already your key. Nothing to change.');

            return false;
        }

        if (! $twoFactor->verifySecret($this->newSecret, $this->newCode)) {
            $this->addError('newCode', 'That code does not match the new key. Enter the code your app shows for the new key.');

            return false;
        }

        $this->newKeyChecked = true;

        return true;
    }

    public function saveNewKey(TwoFactor $twoFactor, string $code): bool
    {
        if (! $this->newKeyChecked || $this->newSecret === '') {
            $this->toast('Check the new key first.', 'error');

            return false;
        }

        if (! $this->confirmCode($twoFactor, $code)) {
            return false;
        }

        $user = auth()->user();
        $user->two_factor_secret = $this->newSecret;
        $user->two_factor_confirmed_at = now();
        $user->save();

        session()->forget('secrets_unlocked_until');
        $this->resetKeyForm();
        $this->toast('Your authenticator key is changed. Codes from the old key no longer work.');

        return true;
    }

    protected function revealableSecret(string $key): ?string
    {
        return $key === 'two_factor_secret' ? auth()->user()->two_factor_secret : null;
    }

    private function validatePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:currentPassword'],
        ], [
            'currentPassword.current_password' => 'This is not your current password.',
            'password.different' => 'The new password must be different from the current one.',
        ]);
    }

    /**
     * The current authenticator code, as the last step of a change.
     */
    private function confirmCode(TwoFactor $twoFactor, string $code): bool
    {
        $this->resetErrorBag('code');

        try {
            $twoFactor->confirmCode(auth()->user(), $code, 'account-security');

            return true;
        } catch (Throwable $exception) {
            $this->addError('code', $exception->getMessage());

            return false;
        }
    }

    private function resetKeyForm(): void
    {
        $this->reset('keyMode', 'newSecret', 'ownSecret', 'newCode', 'newKeyChecked');
        $this->resetErrorBag();
    }

    public function render(TwoFactor $twoFactor)
    {
        $user = auth()->user();

        return view('livewire.account.security', [
            'user' => $user,
            'newQr' => $this->keyMode !== '' && $this->newSecret !== '' ? $twoFactor->qrSvgFor($user->email, $this->newSecret) : null,
        ])->layout('layouts.app')->title('Security');
    }
}
