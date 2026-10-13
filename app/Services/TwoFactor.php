<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

class TwoFactor
{
    public function __construct(private Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function verify(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        return $this->google2fa->verifyKey($user->two_factor_secret, preg_replace('/\s+/', '', $code) ?? '');
    }

    public function currentCode(User $user): string
    {
        return $this->google2fa->getCurrentOtp((string) $user->two_factor_secret);
    }

    public function qrSvg(User $user): string
    {
        $label = rawurlencode($user->email);
        $issuer = rawurlencode('Laravelify');
        $secret = $user->two_factor_secret;
        $url = "otpauth://totp/Laravelify:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        $renderer = new ImageRenderer(
            new RendererStyle(192, 2),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString($url);
    }
}
