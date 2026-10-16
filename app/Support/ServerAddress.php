<?php

namespace App\Support;

use App\Services\CommandRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * This server's public IPv4 address, for links to sites that answer on a port (http://IP:8090).
 * LARAVELIFY_PUBLIC_IP sets it; otherwise it is looked up once a day, only on a real server.
 */
class ServerAddress
{
    private const CACHE_KEY = 'laravelify.public-ip';

    public static function publicIp(): ?string
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
}
