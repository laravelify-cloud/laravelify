<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\CommandRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * This server's address, for links to sites that answer on a port (http://IP:8090). The address
 * set in General settings wins (a server behind NAT, or one only reachable on a local network,
 * like WSL), then LARAVELIFY_PUBLIC_IP; otherwise the public IPv4 is looked up once a day, only
 * on a real server.
 */
class ServerAddress
{
    private const CACHE_KEY = 'laravelify.public-ip';

    public const SETTING = 'server_address';

    public static function publicIp(): ?string
    {
        $chosen = once(fn () => trim((string) Setting::getValue(self::SETTING)));

        if ($chosen !== '') {
            return $chosen;
        }

        return self::detected();
    }

    /** The address found without the setting: LARAVELIFY_PUBLIC_IP, or the public IPv4. */
    public static function detected(): ?string
    {
        $configured = trim((string) config('laravelify.public_ip'));

        if ($configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_IP) !== false ? $configured : null;
        }

        if (! app(CommandRunner::class)->supportsHostCommands()) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached)) {
            return $cached !== '' ? $cached : null;
        }

        try {
            $answer = trim(Http::timeout(4)->get('https://api.ipify.org')->body());
        } catch (Throwable) {
            $answer = '';
        }

        $ip = filter_var($answer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $answer : '';
        // A failed lookup is tried again in ten minutes, a found address kept for a day.
        Cache::put(self::CACHE_KEY, $ip, $ip !== '' ? now()->addDay() : now()->addMinutes(10));

        return $ip !== '' ? $ip : null;
    }

    /**
     * This machine's own network addresses (LAN, WSL), to offer as the server address.
     *
     * @return list<string>
     */
    public static function localIps(): array
    {
        $ips = [];

        foreach (explode(' ', trim((string) @shell_exec('hostname -I 2>/dev/null'))) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && ! str_starts_with($ip, '127.') && ! str_starts_with($ip, '172.17.')) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
