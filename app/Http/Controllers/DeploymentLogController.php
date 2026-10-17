<?php

namespace App\Http\Controllers;

use App\Models\Deployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeploymentLogController extends Controller
{
    /**
     * The part of a deployment's log after an offset (bytes), with the lines coloured for the console,
     * and whether the deployment is still running.
     */
    public function __invoke(Request $request, Deployment $deployment): JsonResponse
    {
        $log = (string) $deployment->log;
        $offset = max(0, (int) $request->query('offset', 0));
        $reset = $offset > strlen($log);
        $offset = $reset ? 0 : $offset;

        return response()->json([
            'text' => $this->coloured(substr($log, $offset)),
            'offset' => strlen($log),
            'reset' => $reset,
            'status' => $deployment->status,
            'live' => in_array($deployment->status, ['queued', 'running'], true),
        ])->header('Cache-Control', 'no-store');
    }

    private function coloured(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return implode("\n", array_map(function (string $line): string {
            $colour = match (true) {
                str_starts_with($line, '$ ') => '36',
                preg_match('/^(error|fatal)\b|\b(failed|exception|fatal error)\b/i', $line) === 1 => '31',
                preg_match('/^(deploy finished|done\b|success)/i', $line) === 1 => '32',
                preg_match('/^(warn|warning|skipped|deploy stopped)/i', $line) === 1 => '33',
                default => null,
            };

            return $colour === null || trim($line) === '' ? $line : "\e[".$colour.'m'.$line."\e[0m";
        }, explode("\n", $text)));
    }
}
