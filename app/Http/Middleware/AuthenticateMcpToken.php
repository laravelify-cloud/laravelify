<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use App\Services\AccessGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint takes an MCP token from Settings → MCP as a Bearer token. Wrong tokens
 * are limited to ten a minute per IP.
 */
class AuthenticateMcpToken
{
    public function __construct(private AccessGate $gate) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $this->gate->clientIp($request);
        $limiter = 'mcp-token:'.$ip;

        if (RateLimiter::tooManyAttempts($limiter, 10)) {
            return $this->deny('Too many wrong tokens. Try again in '.RateLimiter::availableIn($limiter).' seconds.', 429);
        }

        $token = McpToken::findByPlain((string) $request->bearerToken());

        if ($token === null || $token->user === null || ! $token->user->hasConfirmedTwoFactor()) {
            RateLimiter::hit($limiter, 60);

            return $this->deny('Missing or wrong MCP token. Create one in the Laravelify panel under Settings → MCP.', 401);
        }

        RateLimiter::clear($limiter);
        Auth::setUser($token->user);

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->save();
        }

        return $next($request);
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['error' => $message], $status)
            ->header('WWW-Authenticate', 'Bearer realm="Laravelify MCP"');
    }
}
