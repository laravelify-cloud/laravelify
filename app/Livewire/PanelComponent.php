<?php

namespace App\Livewire;

use App\Services\TwoFactor;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Renderless;
use Livewire\Component;

abstract class PanelComponent extends Component
{
    /** How long one authenticator code unlocks saved passwords and tokens. */
    public const SECRET_UNLOCK_MINUTES = 5;

    public string $confirmName = '';

    protected function confirmed(string $expected): bool
    {
        if ($this->confirmName !== $expected) {
            $this->addError('confirmName', 'Type the name exactly to confirm.');
            $this->toast('Type the name exactly to confirm.', 'error');

            return false;
        }

        $this->confirmName = '';
        $this->resetErrorBag('confirmName');

        return true;
    }

    /**
     * Clear an old authenticator-code error when a code dialog opens again.
     */
    public function clearCodeError(): void
    {
        $this->resetErrorBag('code');
    }

    public function boot(): void
    {
        $user = auth()->user();

        abort_unless($user && $user->hasConfirmedTwoFactor(), 403);
    }

    /**
     * Unlock saved passwords and tokens for a few minutes with the current
     * authenticator code. Wrong codes are limited to five a minute.
     *
     * @return array{ok: bool, until?: int, error?: string}
     */
    #[Renderless]
    public function unlockSecrets(string $code): array
    {
        $key = 'secret-unlock:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return ['ok' => false, 'error' => 'Too many wrong codes. Try again in '.RateLimiter::availableIn($key).' seconds.'];
        }

        if (! app(TwoFactor::class)->verify(auth()->user(), $code)) {
            RateLimiter::hit($key, 60);

            return ['ok' => false, 'error' => 'That code is not right. Enter the current 6-digit code from your authenticator app.'];
        }

        RateLimiter::clear($key);
        $until = now()->addMinutes(self::SECRET_UNLOCK_MINUTES)->getTimestamp();
        session(['secrets_unlocked_until' => $until]);

        return ['ok' => true, 'until' => $until * 1000];
    }

    /**
     * A saved secret of this page, while secrets are unlocked.
     *
     * @return array{ok: bool, value?: string, locked?: bool, error?: string}
     */
    #[Renderless]
    public function revealSecret(string $key): array
    {
        if (! self::secretsUnlocked()) {
            return ['ok' => false, 'locked' => true];
        }

        $value = $this->revealableSecret($key);

        return $value === null || $value === ''
            ? ['ok' => false, 'error' => 'Nothing is saved yet.']
            : ['ok' => true, 'value' => $value];
    }

    public static function secretsUnlocked(): bool
    {
        return (int) session('secrets_unlocked_until', 0) > time();
    }

    /**
     * Saved secrets a page allows to be shown, by key. None by default.
     */
    protected function revealableSecret(string $key): ?string
    {
        return null;
    }

    protected function toast(string $message, string $type = 'success'): void
    {
        $this->dispatch('toast', type: $type, message: $message);
    }

    protected function toastAfterRedirect(string $message, string $type = 'success'): void
    {
        session()->flash('toast', ['type' => $type, 'message' => $message]);
    }
}
