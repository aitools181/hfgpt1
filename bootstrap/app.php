<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureScope;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [HandleInertiaRequests::class, SecurityHeaders::class]);
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'active' => EnsureActiveUser::class,
            'scope' => EnsureScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Central exception customization will be added as modules mature.
    })->create();
