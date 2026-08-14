<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Domain services are resolved through the container by type-hint.
    }

    public function boot(): void
    {
        // Phase 0 intentionally keeps global boot logic minimal.
    }
}
