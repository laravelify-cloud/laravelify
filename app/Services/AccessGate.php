<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A browser sign-in prompt (HTTP Basic Auth) in front of the panel and phpMyAdmin.
 *
 * The prompt takes the current authenticator code, a username and password, or both
 * (the code typed right after the password). Allowed IPs never see it. After a right
 * answer the browser is let through for a few hours: the panel keeps that in its session,
 * phpMyAdmin in a signed cookie. Five wrong answers from one IP block it for 15 minutes.
 */
class AccessGate
{
    public const TARGETS = ['panel', 'phpmyadmin'];

    public const METHOD_CODE = 'code';

    public const METHOD_PASSWORD = 'password';

    public const METHOD_PASSWORD_CODE = 'password_code';

    public const METHODS = [self::METHOD_CODE, self::METHOD_PASSWORD, self::METHOD_PASSWORD_CODE];

    public const REMEMBER_HOURS = [1, 4, 12, 24, 72];

    public const COOKIE = 'laravelify_access';

    public const SESSION_KEY = 'access_gate_until';

    private const MAX_FAILURES = 5;

    private const LOCK_SECONDS = 900;

    public function __construct(private TwoFactor $twoFactor) {}

    /**
     * @return array{enabled: bool, method: string, username: string, password_hash: string}
     */
    public function settings(string $target): array
    {
        $saved = json_decode((string) Setting::getValue('access.'.$target), true);
        $saved = is_array($saved) ? $saved : [];

        return [
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'method' => in_array($saved['method'] ?? null, self::METHODS, true) ? $saved['method'] : self::METHOD_CODE,
            'username' => (string) ($saved['username'] ?? ''),
            'password_hash' => (string) ($saved['password_hash'] ?? ''),
        ];
    }

    /**
     * @param  array{enabled: bool, method: string, username: string, password_hash: string}  $settings
     */
    public function saveSettings(string $target, array $settings): void
    {
        Setting::putValue('access.'.$target, json_encode($settings));
    }

    /**
     * @return list<string> IPs and ranges (CIDR) that never see the prompt.
     */
    public function allowlist(): array
    {
        return self::parseAllowlist((string) Setting::getValue('access.allowlist'));
    }

    /**
     * @param  list<string>  $entries
     */
    public function saveAllowlist(array $entries): void
    {
        Setting::putValue('access.allowlist', implode("\n", $entries));
    }

    public function rememberHours(): int
    {
        $hours = (int) Setting::getValue('access.remember_hours');

        return in_array($hours, self::REMEMBER_HOURS, true) ? $hours : 12;
    }

    public function saveRememberHours(int $hours): void
    {
        Setting::putValue('access.remember_hours', (string) $hours);
    }

    /**
     * Allowlist lines, cleaned: one IP or CIDR range each; commas and spaces also separate them.
     *
     * @return list<string>
     */
    public static function parseAllowlist(string $text): array
    {
        $entries = preg_split('/[\s,]+/', trim($text)) ?: [];

        return array_values(array_unique(array_filter($entries, fn (string $entry) => $entry !== '')));
    }

    /**
     * Whether an allowlist line is a valid IP or IP range.
     */
    public static function validEntry(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits >= 0 && (int) $bits <= $max;
    }

    /**
     * The visitor's IP. Behind the Cloudflare tunnel every request comes from this server
     * itself, so the forwarded headers are trusted only then; from anywhere else they could be faked.
     */
    public function clientIp(Request $request): string
    {
        $remote = (string) $request->server->get('REMOTE_ADDR', '');

        if (in_array($remote, ['127.0.0.1', '::1'], true) || str_starts_with($remote, '127.')) {
            foreach (['CF-Connecting-IP', 'True-Client-IP', 'X-Real-IP', 'X-Forwarded-For'] as $header) {
                $value = trim(explode(',', (string) $request->headers->get($header, ''))[0]);

                if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
                    return $value;
                }
            }
        }

        return $remote !== '' ? $remote : 'unknown';
    }

    public function ipAllowed(string $ip): bool
    {
        $entries = $this->allowlist();

        return $entries !== [] && filter_var($ip, FILTER_VALIDATE_IP) !== false && IpUtils::checkIp($ip, $entries);
    }

    /**
     * Check a request against the prompt of a target.
     *
     * @return Response|null Null lets the request through; otherwise the response to send
     *                       (a 401 prompt, or 403 while the IP is blocked).
     */
    public function check(Request $request, string $target): ?Response
    {
        $settings = $this->settings($target);

        if (! $settings['enabled']) {
            return null;
        }

        $ip = $this->clientIp($request);

        if ($this->ipAllowed($ip) || $this->hasPass($request, $target)) {
            return null;
        }

        $limiter = 'access-gate:'.$target.':'.$ip;

        if (RateLimiter::tooManyAttempts($limiter, self::MAX_FAILURES)) {
            return response('Too many wrong tries. Try again in '.ceil(RateLimiter::availableIn($limiter) / 60).' minutes.', 403)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $username = $request->getUser();
        $password = $request->getPassword();

        if ($username === null && $password === null) {
            return $this->challenge($target, $settings['method']);
        }

        $answer = hash('sha256', $target.'|'.$username.'|'.$password);

        if (! $this->accepts($settings, (string) $username, (string) $password)) {
            // Not a new wrong try: the last accepted answer (an old code the browser repeats after
            // the pass ran out), or the same wrong answer again within seconds (the browser loads
            // several files at once, and nginx asks again after its internal redirect).
            $repeated = Cache::get('access-gate:last:'.$target.':'.$ip) === $answer
                || ! Cache::add('access-gate:wrong:'.$target.':'.$ip.':'.$answer, true, 10);

            if (! $repeated) {
                RateLimiter::hit($limiter, self::LOCK_SECONDS);
            }

            return $this->challenge($target, $settings['method']);
        }

        RateLimiter::clear($limiter);
        Cache::put('access-gate:last:'.$target.':'.$ip, $answer, now()->addHours($this->rememberHours() + 24));

        return $this->grant($request, $target);
    }

    /**
     * @param  array{enabled: bool, method: string, username: string, password_hash: string}  $settings
     */
    public function accepts(array $settings, string $username, string $password): bool
    {
        if ($settings['method'] === self::METHOD_CODE) {
            $admin = $this->admin();

            foreach ([$username, $password] as $candidate) {
                if ($admin && preg_match('/^\d{6}$/', trim($candidate)) === 1 && $this->twoFactor->verify($admin, trim($candidate))) {
                    return true;
                }
            }

            return false;
        }

        if ($settings['username'] === '' || $settings['password_hash'] === '' || ! hash_equals($settings['username'], $username)) {
            return false;
        }

        if ($settings['method'] === self::METHOD_PASSWORD) {
            return Hash::check($password, $settings['password_hash']);
        }

        // Password and code: the password, then the 6-digit code right after it.
        if (strlen($password) <= 6) {
            return false;
        }

        $admin = $this->admin();
        $code = substr($password, -6);

        return $admin !== null
            && Hash::check(substr($password, 0, -6), $settings['password_hash'])
            && $this->twoFactor->verify($admin, $code);
    }

    public function admin(): ?User
    {
        return User::query()->whereNotNull('two_factor_confirmed_at')->orderBy('id')->first();
    }

    private function hasPass(Request $request, string $target): bool
    {
        if ($target === 'panel') {
            return $request->hasSession() && (int) $request->session()->get(self::SESSION_KEY, 0) > time();
        }

        try {
            $pass = json_decode(Crypt::decryptString((string) $request->cookies->get(self::COOKIE, '')), true);
        } catch (Throwable) {
            return false;
        }

        return is_array($pass) && ($pass['target'] ?? null) === $target && (int) ($pass['until'] ?? 0) > time();
    }

    private function grant(Request $request, string $target): ?Response
    {
        $until = now()->addHours($this->rememberHours());

        if ($target === 'panel') {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $until->getTimestamp());
            }

            return null;
        }

        // nginx asks for phpMyAdmin pages and passes this cookie on to the browser.
        $value = Crypt::encryptString((string) json_encode(['target' => $target, 'until' => $until->getTimestamp()]));
        $secure = $request->isSecure() || $request->headers->get('X-Forwarded-Proto') === 'https';

        return response('', 204)->withCookie(Cookie::create(self::COOKIE, $value, $until, '/', null, $secure, true, false, Cookie::SAMESITE_LAX));
    }

    private function challenge(string $target, string $method): Response
    {
        $realm = match ($method) {
            self::METHOD_PASSWORD => 'Laravelify '.$target.': username and password',
            self::METHOD_PASSWORD_CODE => 'Laravelify '.$target.': username, then password followed by your 6-digit code',
            default => 'Laravelify '.$target.': your 6-digit authenticator code',
        };

        return response('Sign-in required.', 401)
            ->header('WWW-Authenticate', 'Basic realm="'.$realm.'", charset="UTF-8"')
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
