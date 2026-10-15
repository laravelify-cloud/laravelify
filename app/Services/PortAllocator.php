<?php

namespace App\Services;

use App\Models\Website;

/**
 * Picks ports for new websites that nothing else uses: not another website (in any of its port
 * fields), not a service Laravelify runs, and not anything listening on the server right now,
 * like an app left over from an old server. Ports handed out earlier in the same request count
 * as taken too, so one website never gets the same port twice.
 */
class PortAllocator
{
    /** Ports a website must never take, besides what is listening. */
    public const BLOCKED = [8000, 3306, 6379, 33060];

    /** @var list<int> */
    private array $claimed = [];

    public function __construct(private ServerProcesses $processes) {}

    public function next(int $start): int
    {
        $taken = $this->taken();
        $port = max(1024, $start);

        while (isset($taken[$port])) {
            $port++;
        }

        $this->claimed[] = $port;

        return $port;
    }

    /**
     * Who holds a port, or null when it is free.
     */
    public function holder(int $port): ?string
    {
        if (in_array($port, self::BLOCKED, true) || isset(ServerProcesses::SYSTEM_PORTS[$port])) {
            return ServerProcesses::SYSTEM_PORTS[$port][0] ?? 'a Laravelify service';
        }

        $website = Website::query()
            ->where(fn ($query) => $query->where('port', $port)->orWhere('http_port', $port)->orWhere('reverb_port', $port))
            ->first();

        if ($website) {
            return 'the website '.$website->name;
        }

        $listening = $this->processes->listening()[$port] ?? null;

        return $listening === null ? null : ($listening['processes'][0] ?? 'a process').' (listening now)';
    }

    /**
     * @return array<int, true>
     */
    private function taken(): array
    {
        $ports = [
            ...self::BLOCKED,
            ...array_keys(ServerProcesses::SYSTEM_PORTS),
            ...array_keys($this->processes->listening()),
            ...$this->claimed,
        ];

        foreach (['port', 'http_port', 'reverb_port'] as $column) {
            $ports = [...$ports, ...Website::query()->whereNotNull($column)->pluck($column)->map(fn ($port) => (int) $port)->all()];
        }

        return array_fill_keys($ports, true);
    }
}
