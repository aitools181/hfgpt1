<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LiveHealthController;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureScope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Operational health routes deliberately live outside the `web`
            // middleware group. Cookie/session/CSRF/Inertia failures therefore
            // cannot hide application readiness diagnostics.
            Route::get('/health/live', LiveHealthController::class)->name('health.live');
            Route::get('/health/ready', HealthController::class)->name('health.ready');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->prepend(RequestCorrelation::class);
        $middleware->web(append: [HandleInertiaRequests::class, SecurityHeaders::class]);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'active' => EnsureActiveUser::class,
            'scope' => EnsureScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->context(function (): array {
            if (! app()->bound('request')) {
                return [];
            }
            $request = app('request');
            return [
                'request_id' => $request->attributes->get('request_id'),
                'request_method' => $request->method(),
                'request_path' => $request->path(),
            ];
        });
    })->create();
