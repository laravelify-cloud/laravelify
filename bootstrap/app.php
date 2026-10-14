<?php

use App\Http\Controllers\AccessGateController;
use App\Http\Middleware\PanelAccess;
use App\Http\Middleware\PanelGate;
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
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: [
            'laravelify-theme',
            'laravelify-tz',
            'laravelify-locale',
        ]);

        $middleware->web(append: [
            PanelAccess::class,
            PanelGate::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'hooks/github/*',
            'hooks/github-app/*',
            'hooks/heartbeat/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
