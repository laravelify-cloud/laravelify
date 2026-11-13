<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Spring Boot's health endpoint (/actuator/health, from spring-boot-starter-actuator) on the
 * app's own 127.0.0.1 port. A JVM takes a while to start, so a deploy waits for it here.
 */
class SpringHealth
{
    public const PATH = '/actuator/health';

    /**
     * One look at the endpoint: "up", "down" (it answers, but a part like the database is down),
     * "missing" (the app answers 404: no actuator), or "none" (nothing answers yet).
     *
     * @return array{state: string, detail: string}
     */
    public function check(Website $website): array
    {
        $url = 'http://127.0.0.1:'.(int) $website->port.self::PATH;

        try {
            $response = Http::timeout(5)->withoutRedirecting()->acceptJson()->get($url);
        } catch (Throwable) {
            return ['state' => 'none', 'detail' => 'Nothing answers on '.$url.' yet.'];
        }

        if ($response->status() === 404) {
            return ['state' => 'missing', 'detail' => 'The app answers, but has no '.self::PATH.'. Add spring-boot-starter-actuator to see its health here.'];
        }

        $status = strtoupper((string) ($response->json('status') ?? ''));

        if ($response->successful() && $status === 'UP') {
            return ['state' => 'up', 'detail' => self::PATH.' says UP.'];
        }

        $down = collect((array) $response->json('components', []))
            ->filter(fn ($part): bool => is_array($part) && strtoupper((string) ($part['status'] ?? '')) !== 'UP')
            ->keys()->implode(', ');

        return ['state' => 'down', 'detail' => self::PATH.' says '.($status ?: 'HTTP '.$response->status()).($down !== '' ? ' (down: '.$down.')' : '').'.'];
    }

    /**
     * Wait for the app after a deploy, up to $seconds. Returns the last check.
     *
     * @return array{state: string, detail: string}
     */
    public function waitUntilReady(Website $website, int $seconds = 120): array
    {
        for ($attempt = 1; ; $attempt++) {
            $result = $this->check($website);

            if ($result['state'] !== 'none' || $attempt * 2 >= $seconds) {
                return $result;
            }

            Sleep::for(2)->seconds();
        }
    }
}
