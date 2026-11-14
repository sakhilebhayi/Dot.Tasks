<?php

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseTeamClock;
use App\Services\Guardian\ErrorCounter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so the health check, error pages, Livewire requests and /csp-report carry the headers too.
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [UseTeamClock::class, EnsureCompanyEntitled::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Feeds the Guardian's hourly error counter. The counter swallows its own failures, so this cannot break reporting.
        $exceptions->report(function (Throwable $e): void {
            app(ErrorCounter::class)->record($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
