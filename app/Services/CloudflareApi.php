<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Talks to the Cloudflare API with the token saved in Settings → Cloudflare: the tunnel's
 * public hostnames (its ingress rules) and the DNS records that point to the tunnel.
 *
 * The token needs: Account → Cloudflare Tunnel → Edit, and Zone → DNS → Edit (with Zone → Zone → Read).
 */
class CloudflareApi
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    public const SETTING = 'cloudflare_api_token';

    public function token(): ?string
    {
        $token = Setting::getValue(self::SETTING);

        return filled($token) ? (string) $token : null;
    }

    public function configured(): bool
    {
        return $this->token() !== null;
    }

    /**
     * The account and tunnel ids inside a tunnel token (it is base64 of {"a": account, "t": tunnel, "s": secret}).
     *
     * @return array{account: string, tunnel: string}|null
     */
    public function tunnelIds(CloudflareTunnel $tunnel): ?array
    {
        $raw = trim((string) $tunnel->token);

        if (preg_match('/--token\s+(\S+)/', $raw, $match) === 1) {
            $raw = $match[1];
        }

        $decoded = json_decode((string) base64_decode(strtr($raw, '-_', '+/'), true), true);

        if (! is_array($decoded) || ! is_string($decoded['a'] ?? null) || ! is_string($decoded['t'] ?? null)) {
            return null;
        }

        return ['account' => $decoded['a'], 'tunnel' => $decoded['t']];
    }

    /**
     * Check the token. Returns what Cloudflare says about it.
     *
     * @return array{status: string, zones: list<string>}
     */
    public function verify(): array
    {
        $verify = $this->request('GET', '/user/tokens/verify');
        $zones = $this->request('GET', '/zones', ['per_page' => 50]);

        return [
            'status' => (string) ($verify['status'] ?? 'unknown'),
            'zones' => array_values(array_map(fn (array $zone): string => (string) $zone['name'], (array) $zones)),
        ];
    }

    /**
     * The tunnel's remote configuration (ingress rules and other settings).
     *
     * @return array<string, mixed>
     */
    public function tunnelConfig(string $account, string $tunnel): array
    {
        $result = $this->request('GET', "/accounts/{$account}/cfd_tunnel/{$tunnel}/configurations");

        return is_array($result['config'] ?? null) ? $result['config'] : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function putTunnelConfig(string $account, string $tunnel, array $config): void
    {
        $this->request('PUT', "/accounts/{$account}/cfd_tunnel/{$tunnel}/configurations", ['config' => $config]);
    }

    /**
     * The zone (domain on Cloudflare) a hostname belongs to: the longest matching name wins.
     *
     * @return array{id: string, name: string}|null
     */
    public function zoneFor(string $host): ?array
    {
        $labels = explode('.', $host);

        while (count($labels) >= 2) {
            $name = implode('.', $labels);
            $zones = $this->request('GET', '/zones', ['name' => $name]);

            if (is_array($zones) && isset($zones[0]['id'])) {
                return ['id' => (string) $zones[0]['id'], 'name' => (string) $zones[0]['name']];
            }

            array_shift($labels);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dnsRecords(string $zone, string $name): array
    {
        $records = $this->request('GET', "/zones/{$zone}/dns_records", ['name' => $name]);

        return array_values(array_filter((array) $records, 'is_array'));
    }

    public function createCname(string $zone, string $name, string $target): void
    {
        $this->request('POST', "/zones/{$zone}/dns_records", [
            'type' => 'CNAME',
            'name' => $name,
            'content' => $target,
            'proxied' => true,
            'ttl' => 1,
            'comment' => 'Laravelify tunnel',
        ]);
    }

    public function createA(string $zone, string $name, string $ip): void
    {
        $this->request('POST', "/zones/{$zone}/dns_records", [
            'type' => 'A',
            'name' => $name,
            'content' => $ip,
            'proxied' => true,
            'ttl' => 1,
            'comment' => 'Laravelify redirect',
        ]);
    }

    /**
     * This server's public IPv4 address, as the internet sees it.
     */
    public function serverIp(): ?string
    {
        try {
            $ip = trim(Http::timeout(6)->get('https://api.ipify.org')->body());
        } catch (Throwable) {
            return null;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $ip : null;
    }

    public function deleteRecord(string $zone, string $id): void
    {
        $this->request('DELETE', "/zones/{$zone}/dns_records/{$id}");
    }

    /**
     * One API call. Returns the "result" part; throws with Cloudflare's own message when it fails.
     *
     * @param  array<string, mixed>  $data
     */
    private function request(string $method, string $path, array $data = []): mixed
    {
        $token = $this->token() ?? throw new RuntimeException('No Cloudflare API token is saved. Add one in Settings → Cloudflare.');
        $client = Http::withToken($token)->acceptJson()->timeout(20)->retry(2, 300, throw: false);
        $url = self::BASE.$path;

        $response = match ($method) {
            'GET' => $client->get($url, $data),
            'POST' => $client->post($url, $data),
            'PUT' => $client->put($url, $data),
            'DELETE' => $client->delete($url),
            default => throw new RuntimeException('Unsupported method.'),
        };

        if (! $response->successful() || $response->json('success') !== true) {
            $message = (string) ($response->json('errors.0.message') ?? 'HTTP '.$response->status());

            throw new RuntimeException($this->explain($response->status(), $message));
        }

        return $response->json('result');
    }

    private function explain(int $status, string $message): string
    {
        return match (true) {
            $status === 401 => 'Cloudflare rejected the API token ('.$message.'). Make a new one in Settings → Cloudflare.',
            $status === 403 => 'The Cloudflare token lacks a permission ('.$message.'). It needs Account → Cloudflare Tunnel → Edit and Zone → DNS → Edit.',
            default => 'Cloudflare said: '.$message,
        };
    }
}
