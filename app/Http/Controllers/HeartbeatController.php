<?php

namespace App\Http\Controllers;

use App\Models\Heartbeat;
use App\Services\HeartbeatMonitor;
use Illuminate\Http\Response;

class HeartbeatController extends Controller
{
    /**
     * `curl -fsS https://panel/hooks/heartbeat/{token}` at the end of a cron job.
     */
    public function __invoke(string $token, HeartbeatMonitor $monitor): Response
    {
        $heartbeat = Heartbeat::query()->with('website')->where('token', $token)->firstOrFail();

        $monitor->ping($heartbeat);

        return response('OK', 200, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
    }
}
