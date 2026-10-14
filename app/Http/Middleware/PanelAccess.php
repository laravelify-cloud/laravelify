<?php

namespace App\Http\Middleware;

use App\Services\AccessGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser sign-in prompt in front of the whole panel (Security → Access), before the
 * panel's own login. Webhooks and the health check stay open so deploys and heartbeats work.
 */
class PanelAccess
{
    public function __construct(private AccessGate $gate) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('hooks/*') || $request->is('up')) {
            return $next($request);
        }

        return $this->gate->check($request, 'panel') ?? $next($request);
    }
}
