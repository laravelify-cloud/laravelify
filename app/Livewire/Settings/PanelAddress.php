<?php

namespace App\Livewire\Settings;

use App\Livewire\PanelComponent;
use App\Models\CloudflareTunnel;
use App\Services\CloudflareApi;
use App\Services\CloudflareRoutes;
use App\Services\HostWriter;
use App\Support\ServerAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Settings -> Panel address: move the panel from its first address (http://IP:8000) to a domain.
 * The domain is added next to the port and tested over HTTPS first, so one that does not work
 * changes nothing.
 */
class PanelAddress extends PanelComponent
{
    public const CHECK_KEY = 'laravelify.panel-address-check';

    private const CHECK_TRIES = 6;

    public string $domain = '';

    /** cloudflare: Laravelify makes the DNS record or tunnel route. dns: you made the record. */
    public string $method = 'dns';

    public string $tunnel = '';

    public ?string $problem = null;

    public function mount(CloudflareApi $api): void
    {
        $this->method = $api->configured() ? 'cloudflare' : 'dns';
    }

    public function move(CloudflareApi $api, CloudflareRoutes $routes, HostWriter $host): void
    {
        $this->domain = strtolower(trim($this->domain));
        $this->problem = null;

        $this->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?=.*[a-z])[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/'],
            'method' => ['required', 'in:cloudflare,dns'],
        ], ['domain.regex' => 'Write a domain like panel.example.com, without https://.']);

        try {
            if ($this->method === 'cloudflare') {
                $this->pointCloudflare($api, $routes);
            }

            $host->panelDomainAdd($this->domain);
        } catch (Throwable $exception) {
            $this->problem = $exception->getMessage();

            return;
        }

        $failure = $this->check();

        if ($failure !== null) {
            $host->panelDomainRestore();
            $this->problem = $failure;

            return;
        }

        $host->panelDomainCommit($this->domain);
        $this->redirect('https://'.$this->domain.'/settings/panel-address');
    }

    /** Make the tunnel route (when a tunnel is picked) or a proxied A record. */
    private function pointCloudflare(CloudflareApi $api, CloudflareRoutes $routes): void
    {
        if (! $api->configured()) {
            throw new RuntimeException('Save a Cloudflare API token in Settings → Cloudflare first, or choose the DNS record option.');
        }

        $tunnel = $this->tunnel !== '' ? CloudflareTunnel::query()->find($this->tunnel) : null;
        $result = $tunnel
            ? $routes->route($tunnel, $this->domain, 'http://127.0.0.1:80', null)
            : $routes->syncAddresses([$this->domain]);

        foreach ($result['lines'] as $line) {
            if (! $line['ok']) {
                throw new RuntimeException('Cloudflare: '.$line['dns']);
            }
        }

        if ($result['lines'] === []) {
            throw new RuntimeException($result['note'] ?? 'Cloudflare did not take the domain.');
        }
    }

    /** The useful part of an HTTP client error (cURL's own words), without any address details. */
    private static function reason(string $message): string
    {
        $message = (string) preg_replace('/^cURL error \d+: /', '', trim($message));
        $message = (string) preg_replace('/\s*\(see https?:\/\/[^)]*\)/', '', $message);
        $message = (string) preg_replace('/\s+for https?:\/\/\S+/', '', $message);

        return Str::limit($message, 160);
    }

    /** The domain's address from Cloudflare's public DNS (over HTTPS), for when the server's DNS does not know it yet. */
    private function publicIp(): ?string
    {
        // Cloudflare's, then Google's (both answer in the same JSON form); some networks block one.
        foreach (['https://1.1.1.1/dns-query', 'https://dns.google/resolve'] as $resolver) {
            try {
                $answers = Http::timeout(5)->withHeaders(['Accept' => 'application/dns-json'])
                    ->get($resolver, ['name' => $this->domain, 'type' => 'A'])
                    ->json('Answer', []);
            } catch (Throwable) {
                continue;
            }

            foreach ((array) $answers as $answer) {
                $ip = (string) ($answer['data'] ?? '');

                if (($answer['type'] ?? null) === 1 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /** Null when https://domain reaches this panel, otherwise what to do about it. */
    private function check(): ?string
    {
        $value = Str::random(40);
        Cache::put(self::CHECK_KEY, $value, now()->addMinutes(3));
        $status = null;
        $reason = null;

        try {
            for ($try = 1; $try <= self::CHECK_TRIES; $try++) {
                try {
                    $request = Http::timeout(6);

                    // The server's own DNS can still hold "no such name" from before the record
                    // existed (for up to 30 minutes). Only this check needs it: ask Cloudflare's DNS.
                    if ($reason !== null && str_contains($reason, 'resolve host')) {
                        $ip = $this->publicIp();

                        // Waiting does not fix this server's DNS: say so after a second try.
                        if ($ip === null && $try > 2) {
                            break;
                        }

                        if ($ip !== null) {
                            $request = $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$this->domain.':443:'.$ip]]]);
                        }
                    }

                    $response = $request->get('https://'.$this->domain.'/_laravelify/panel-check');
                    $status = $response->status();

                    if (trim($response->body()) === $value) {
                        return null;
                    }
                } catch (Throwable $exception) {
                    $status = null;
                    $reason = self::reason($exception->getMessage());
                }

                if ($try < self::CHECK_TRIES) {
                    Sleep::for(5)->seconds();
                }
            }
        } finally {
            Cache::forget(self::CHECK_KEY);
        }

        return match (true) {
            $status === null && $reason !== null && str_contains($reason, 'resolve host') => "This server's DNS does not know {$this->domain} yet ({$reason}), though Cloudflare has it. Run `getent hosts {$this->domain}` on the server: when it finds nothing, point /etc/resolv.conf to systemd-resolved (ln -sfn /run/systemd/resolve/stub-resolv.conf /etc/resolv.conf) or to 1.1.1.1. Nothing was changed.",
            $status === null => "This server could not reach https://{$this->domain}".($reason ? " ({$reason})" : '').'. Check that the DNS record exists and has the orange cloud (Proxied) in Cloudflare, and that this server can open https:// addresses; a new record can take a few minutes. Nothing was changed.',
            in_array($status, [521, 522, 525, 526], true) => "Cloudflare could not reach this server (error {$status}). Set SSL/TLS to Flexible for this domain, or use a tunnel, and check that port 80 is open. Nothing was changed.",
            default => "https://{$this->domain} answered with status {$status}, but not from this panel. Check where the DNS record points. Nothing was changed.",
        };
    }

    public function render()
    {
        $url = (string) config('app.url');

        return view('livewire.settings.panel-address', [
            'currentUrl' => $url,
            'onPort' => str_starts_with($url, 'http://'),
            'serverIp' => ServerAddress::publicIp(),
            'hasToken' => app(CloudflareApi::class)->configured(),
            'tunnels' => CloudflareTunnel::query()->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app')->title('Panel address');
    }
}
