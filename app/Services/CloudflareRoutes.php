<?php

namespace App\Services;

use App\Models\CloudflareTunnel;
use App\Models\Website;
use Throwable;

/**
 * Keeps Cloudflare in step with a website's domains, when an API token is saved: a public
 * hostname on the tunnel (so Cloudflare sends the domain through it) and a proxied CNAME to
 * <tunnel>.cfargotunnel.com (so the domain's DNS points at it). Other routes and records of the
 * account are never touched, and a DNS record that already exists for another purpose is reported,
 * not replaced.
 */
class CloudflareRoutes
{
    public function __construct(private CloudflareApi $api) {}

    /**
     * Add the website's domains to its tunnel and DNS. Domains in $removed are taken off again.
     *
     * @param  list<string>  $removed
     * @return array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}
     */
    public function sync(Website $website, array $removed = []): array
    {
        $tunnel = $website->tunnel;

        if (! $tunnel) {
            return ['configured' => $this->api->configured(), 'lines' => [], 'note' => null];
        }

        $service = 'http://127.0.0.1:'.($website->http_port ?: 80);
        $desired = array_fill_keys($website->hostnames(), $service);

        return $this->reconcile($tunnel, $desired, array_values(array_diff($removed, array_keys($desired))));
    }

    /**
     * Take domains off a tunnel and delete the CNAMEs that point to it (a website was deleted or moved).
     *
     * @param  list<string>  $hosts
     * @return array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}
     */
    public function forget(CloudflareTunnel $tunnel, array $hosts): array
    {
        return $hosts === [] ? ['configured' => $this->api->configured(), 'lines' => [], 'note' => null] : $this->reconcile($tunnel, [], $hosts);
    }

    /**
     * @param  array<string, string>  $desired  hostname => origin service
     * @param  list<string>  $removed
     * @return array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}
     */
    private function reconcile(CloudflareTunnel $tunnel, array $desired, array $removed): array
    {
        if (! $this->api->configured()) {
            return [
                'configured' => false,
                'lines' => [],
                'note' => $desired === [] ? null : 'No Cloudflare API token is saved, so add '.implode(', ', array_keys($desired)).' in Cloudflare yourself (tunnel → Public hostnames, service http://localhost:80). Save a token in Settings → Cloudflare and Laravelify does this for you.',
            ];
        }

        $ids = $this->api->tunnelIds($tunnel);

        if ($ids === null) {
            return ['configured' => true, 'lines' => [], 'note' => 'The tunnel token does not look like a Cloudflare tunnel token, so its routes could not be set.'];
        }

        $lines = [];
        $routeStatus = [];
        $routeError = null;

        try {
            $config = $this->api->tunnelConfig($ids['account'], $ids['tunnel']);
            [$ingress, $routeStatus, $changed] = $this->mergeIngress((array) ($config['ingress'] ?? []), $desired, $removed);

            if ($changed) {
                $config['ingress'] = $ingress;
                $this->api->putTunnelConfig($ids['account'], $ids['tunnel'], $config);
            }
        } catch (Throwable $exception) {
            $routeError = $exception->getMessage();
        }

        $target = $ids['tunnel'].'.cfargotunnel.com';

        foreach ($desired as $host => $service) {
            $lines[] = $this->line($host, $routeError !== null ? 'error: '.$routeError : ($routeStatus[$host] ?? 'ok'), $this->ensureCname($host, $target), $routeError);
        }

        foreach ($removed as $host) {
            $lines[] = $this->line($host, $routeError !== null ? 'error: '.$routeError : ($routeStatus[$host] ?? 'removed'), $this->removeCname($host, $target), $routeError);
        }

        return ['configured' => true, 'lines' => $lines, 'note' => null];
    }

    /**
     * Put our hostnames into the ingress rules and take removed ones out, leaving every other rule alone.
     *
     * @param  list<array<string, mixed>>  $ingress
     * @param  array<string, string>  $desired
     * @param  list<string>  $removed
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>, 2: bool}
     */
    private function mergeIngress(array $ingress, array $desired, array $removed): array
    {
        $status = [];
        $changed = false;
        $rules = array_values(array_filter($ingress, fn ($rule) => is_array($rule) && isset($rule['hostname'])));
        $fallback = collect($ingress)->first(fn ($rule) => is_array($rule) && ! isset($rule['hostname'])) ?? ['service' => 'http_status:404'];

        foreach ($removed as $host) {
            $before = count($rules);
            $rules = array_values(array_filter($rules, fn (array $rule): bool => strtolower((string) $rule['hostname']) !== $host));

            if (count($rules) !== $before) {
                $changed = true;
                $status[$host] = 'removed';
            }
        }

        foreach ($desired as $host => $service) {
            $index = collect($rules)->search(fn (array $rule): bool => strtolower((string) $rule['hostname']) === $host);

            if ($index === false) {
                $rules[] = ['hostname' => $host, 'service' => $service];
                $status[$host] = 'added';
                $changed = true;
            } elseif (($rules[$index]['service'] ?? null) !== $service) {
                $rules[$index]['service'] = $service;
                $status[$host] = 'updated';
                $changed = true;
            } else {
                $status[$host] = 'ok';
            }
        }

        return [[...$rules, $fallback], $status, $changed];
    }

    private function ensureCname(string $host, string $target): string
    {
        try {
            $zone = $this->api->zoneFor($host);

            if ($zone === null) {
                return 'error: this domain is not on the Cloudflare account of the token';
            }

            $records = $this->api->dnsRecords($zone['id'], $host);

            if ($records === []) {
                $this->api->createCname($zone['id'], $host, $target);

                return 'created';
            }

            foreach ($records as $record) {
                if (($record['type'] ?? '') === 'CNAME' && strtolower((string) ($record['content'] ?? '')) === $target) {
                    return 'ok';
                }
            }

            $type = (string) ($records[0]['type'] ?? 'record');

            return 'error: a '.$type.' record for '.$host.' already exists. Delete it in Cloudflare (DNS), then save again';
        } catch (Throwable $exception) {
            return 'error: '.$exception->getMessage();
        }
    }

    private function removeCname(string $host, string $target): string
    {
        try {
            $zone = $this->api->zoneFor($host);

            if ($zone === null) {
                return 'none';
            }

            foreach ($this->api->dnsRecords($zone['id'], $host) as $record) {
                if (($record['type'] ?? '') === 'CNAME' && strtolower((string) ($record['content'] ?? '')) === $target) {
                    $this->api->deleteRecord($zone['id'], (string) $record['id']);

                    return 'removed';
                }
            }

            return 'none';
        } catch (Throwable $exception) {
            return 'error: '.$exception->getMessage();
        }
    }

    /**
     * @return array{host: string, route: string, dns: string, ok: bool}
     */
    private function line(string $host, string $route, string $dns, ?string $routeError): array
    {
        return [
            'host' => $host,
            'route' => $route,
            'dns' => $dns,
            'ok' => $routeError === null && ! str_starts_with($dns, 'error'),
        ];
    }

    /**
     * One sentence per problem, for a toast or an MCP note.
     *
     * @param  array{configured: bool, lines: list<array{host: string, route: string, dns: string, ok: bool}>, note: ?string}  $report
     * @return list<string>
     */
    public static function problems(array $report): array
    {
        $problems = $report['note'] !== null ? [$report['note']] : [];

        foreach ($report['lines'] as $line) {
            foreach (['route' => 'tunnel route', 'dns' => 'DNS'] as $key => $label) {
                if (str_starts_with($line[$key], 'error')) {
                    $problems[] = $line['host'].' ('.$label.'): '.trim(substr($line[$key], 6));
                }
            }
        }

        return $problems;
    }
}
