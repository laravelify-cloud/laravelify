<?php

use App\Http\Controllers\AccessGateController;
use App\Http\Controllers\CodeEditorGateController;
use App\Http\Middleware\PanelAccess;
use App\Http\Middleware\PanelGate;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // nginx asks here before phpMyAdmin pages. No web middleware: no session, no panel login.
            Route::get('/_access/phpmyadmin', AccessGateController::class)->name('access.phpmyadmin');
            // nginx asks here before VS Code pages: the panel session says who is signed in. It
            // answers 204 or 401 itself, so the panel's login redirects are left out.
            Route::get('/_access/code', CodeEditorGateController::class)
                ->middleware('web')
                ->withoutMiddleware([PanelGate::class, PanelAccess::class])
                ->name('access.code');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only this server's own proxies (cloudflared, nginx) may say who the visitor is; a forwarded
        // header from anywhere else could fake the address, the scheme, or the host of links.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->encryptCookies(except: [
            'laravelify-theme',
            'laravelify-tz',
            'laravelify-locale',
        ]);

        $middleware->web(append: [
            SecurityHeaders::class,
            PanelAccess::class,
            PanelGate::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'hooks/github/*',
            'hooks/github-app/*',
            'hooks/live/*',
            'hooks/heartbeat/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
