<?php

namespace App\Services;

use App\Models\Website;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Helps pick a website's domain: the Cloudflare zones the saved API token can see, the host
 * names already used in each (marked when a website of this panel answers on them), and free
 * random subdomains.
 */
class DomainSuggestions
{
    private const WORDS = ['amber', 'brisk', 'calm', 'cedar', 'coral', 'dawn', 'ember', 'fern', 'frost', 'glow', 'harbor', 'ivy', 'jade', 'lunar', 'maple', 'mint', 'nova', 'oak', 'pine', 'quartz', 'river', 'sage', 'solar', 'swift', 'tide', 'vale', 'willow', 'zephyr'];

    public function __construct(private CloudflareApi $cloudflare) {}

    /**
     * @return array{configured: bool, error: ?string, zones: list<array{name: string, records: list<array{name: string, type: string, content: string, website: ?string}>}>}
     */
    public function options(bool $fresh = false): array
    {
        if (! $this->cloudflare->configured()) {
            return ['configured' => false, 'error' => null, 'zones' => []];
        }

        if ($fresh) {
            Cache::forget('domain-suggestions');
        }

        try {
            $zones = Cache::remember('domain-suggestions', now()->addMinutes(2), function (): array {
                $zones = [];

                foreach ($this->cloudflare->zones() as $zone) {
                    $zones[] = ['name' => $zone['name'], 'records' => $this->cloudflare->hostnames($zone['id'])];
                }

                return $zones;
            });
        } catch (Throwable $exception) {
            return ['configured' => true, 'error' => $exception->getMessage(), 'zones' => []];
        }

        $owners = $this->owners();

        foreach ($zones as &$zone) {
            foreach ($zone['records'] as &$record) {
                $record['website'] = $owners[$record['name']] ?? null;
            }
        }

        return ['configured' => true, 'error' => null, 'zones' => $zones];
    }

    /**
     * A free subdomain of the zone, like "calm-river-4f2a.example.com".
     */
    public function random(string $zone): string
    {
        $zone = strtolower(trim($zone));
        $used = $this->usedNames();

        do {
            $name = self::WORDS[array_rand(self::WORDS)].'-'.self::WORDS[array_rand(self::WORDS)].'-'.bin2hex(random_bytes(2)).'.'.$zone;
        } while (isset($used[$name]));

        return $name;
    }

    /**
     * Which website answers on each host name (its domain and its other domains).
     *
     * @return array<string, string>
     */
    private function owners(): array
    {
        $owners = [];

        foreach (Website::query()->get(['name', 'domain', 'aliases']) as $website) {
            foreach (array_filter([$website->domain, ...((array) $website->aliases)]) as $host) {
                $owners[strtolower((string) $host)] = $website->name;
            }
        }

        return $owners;
    }

    /**
     * @return array<string, true>
     */
    private function usedNames(): array
    {
        $used = array_fill_keys(array_keys($this->owners()), true);

        foreach ($this->options()['zones'] as $zone) {
            foreach ($zone['records'] as $record) {
                $used[$record['name']] = true;
            }
        }

        return $used;
    }
}
