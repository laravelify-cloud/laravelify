<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Throwable;

class TwoFactor
{
    /** Shortest key Google Authenticator and the TOTP library accept (16 base32 characters = 80 bits). */
    public const MIN_SECRET_LENGTH = 16;

    public const MAX_SECRET_LENGTH = 128;

    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function verify(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        return $this->verifySecret($user->two_factor_secret, $code);
    }

    /**
     * Whether a code fits a key that is not saved yet (a new or a pasted key).
     */
    public function verifySecret(string $secret, string $code): bool
    {
        try {
            return $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code) ?? '');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Check the current code of the signed-in user, five wrong codes a minute at most.
     *
     * @throws RuntimeException With a message to show when the code is wrong.
     */
    public function confirmCode(User $user, string $code, string $purpose = 'confirm'): void
    {
        $key = $purpose.':'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new RuntimeException('Too many wrong codes. Try again in '.RateLimiter::availableIn($key).' seconds.');
        }

        if (! $this->verify($user, trim($code))) {
            RateLimiter::hit($key, 60);

            throw new RuntimeException('That code is not right. Enter the current 6-digit code from your authenticator app.');
        }

        RateLimiter::clear($key);
    }

    /**
     * A key typed or pasted by the user, cleaned up: spaces and dashes removed, upper case, no padding.
     * An otpauth:// link (what a QR code holds) works too. Null when it is not a usable key.
     */
    public function normalizeSecret(string $input): ?string
    {
        $input = trim($input);

        if (str_starts_with(strtolower($input), 'otpauth://')) {
            parse_str((string) parse_url($input, PHP_URL_QUERY), $query);
            $input = is_string($query['secret'] ?? null) ? $query['secret'] : '';
        }

        $secret = rtrim(strtoupper(preg_replace('/[\s-]+/', '', $input) ?? ''), '=');

        if (preg_match('/^[A-Z2-7]+$/', $secret) !== 1) {
            return null;
        }

        $length = strlen($secret);

        return $length >= self::MIN_SECRET_LENGTH && $length <= self::MAX_SECRET_LENGTH ? $secret : null;
    }

    public function currentCode(User $user): string
    {
        return $this->google2fa->getCurrentOtp((string) $user->two_factor_secret);
    }

    public function qrSvg(User $user): string
    {
        return $this->qrSvgFor($user->email, (string) $user->two_factor_secret);
    }

    /**
     * A QR code for an authenticator app, for any key (the saved one or a new one).
     */
    public function qrSvgFor(string $email, string $secret): string
    {
        $label = rawurlencode($email);
        $issuer = rawurlencode('Laravelify');
        $url = "otpauth://totp/Laravelify:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        $renderer = new ImageRenderer(
            new RendererStyle(192, 2),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($url);
    }
}
