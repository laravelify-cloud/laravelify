<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\RequestCollector;
use App\Services\ServerStats;
use App\Services\SiteSecurity;
use App\Services\ThreatDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small JSON feeds the monitoring pages poll every few seconds. They read the
 * request log and /proc directly, so they are live without waiting for the
 * once-a-minute collector, and the page itself never re-renders.
 */
class MonitoringLiveController extends Controller
{
    /**
     * On the first call, look back this far so the feed is not empty.
     */
    private const FIRST_READ = 96 * 1024;

    private const MAX_READ = 512 * 1024;

    private const MAX_ROWS = 150;

    /**
     * Requests appended to the site's log since the byte offset the browser holds.
     */
    public function website(Request $request, Website $website, RequestCollector $collector): JsonResponse
    {
        $path = SiteSecurity::metricsLog($website);
        clearstatcache(true, $path);

        if (! is_file($path) || ! is_readable($path)) {
            return response()->json(['tracking' => false, 'offset' => 0, 'requests' => []]);
        }

        $size = (int) filesize($path);
        $offset = $request->integer('offset', -1);
        $first = $offset < 0 || $offset > $size;

        // First call, or the log was rotated: start a little before the end.
        if ($first) {
            $offset = max(0, $size - self::FIRST_READ);
        }

        $rows = [];

        if ($size > $offset && ($handle = @fopen($path, 'rb')) !== false) {
            fseek($handle, $offset);
            $text = (string) fread($handle, min(self::MAX_READ, $size - $offset));
            fclose($handle);

            if ($first && $offset > 0) {
                $text = (string) substr($text, (int) strpos($text, "\n") + 1);
            }

            $lastNewline = strrpos($text, "\n");
            $complete = $lastNewline === false ? '' : substr($text, 0, $lastNewline + 1);
            $offset = $first ? $size - (strlen($text) - strlen($complete)) : $offset + strlen($complete);

            foreach (explode("\n", $complete) as $line) {
                $row = $collector->parse($website, $line);

                if ($row !== null) {
                    $rows[] = [
                        't' => strtotime($row['at']),
                        'm' => $row['method'],
                        'u' => mb_strimwidth($row['uri'], 0, 160, '…'),
                        's' => $row['status'],
                        'd' => $row['duration_ms'],
                        'ip' => $row['ip'],
                        'c' => $row['country'],
                        'r' => $row['risk'],
                        'th' => $row['threat'] ? ThreatDetector::label($row['threat']) : null,
                    ];
                }
            }
        }

        return response()->json([
            'tracking' => true,
            'offset' => $offset,
            'now' => time(),
            'slow' => (int) ($website->monitor_slow_ms ?: 2000),
            'requests' => array_slice($rows, -self::MAX_ROWS),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * CPU, memory, load, and network right now. Processes only when asked (they cost a ps call).
     */
    public function server(Request $request, ServerStats $stats): JsonResponse
    {
        return response()->json($stats->live($request->boolean('processes')), 200, ['Cache-Control' => 'no-store']);
    }
}
