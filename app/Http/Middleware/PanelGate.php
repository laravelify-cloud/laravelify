<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PanelGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('hooks/*') || $request->is('livewire*') || $request->is('up')) {
            return $next($request);
        }

        $hasUser = User::query()->exists();

        if (! $hasUser) {
            if (! $request->routeIs('setup')) {
                return redirect()->route('setup');
            }

            return $next($request);
        }

        if ($request->routeIs('setup')) {
            return redirect()->route(auth()->check() ? 'dashboard' : 'login');
        }

        if (! auth()->check()) {
            // The password form, and Open panel from Laravelify Cloud (its own checks, then the authenticator code).
            if ($request->routeIs('login', 'cloud-login', 'cloud-login.confirm')) {
                return $next($request);
            }

            return redirect()->route('login');
        }

        $user = auth()->user();

        if (! $user->hasConfirmedTwoFactor()) {
            if (! $request->routeIs('setup.two-factor') && ! $request->routeIs('logout')) {
                return redirect()->route('setup.two-factor');
            }

            return $next($request);
        }

        if ($request->routeIs('setup', 'setup.two-factor', 'login', 'cloud-login', 'cloud-login.confirm')) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
