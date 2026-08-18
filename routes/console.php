<?php

use App\Services\Field\InactivityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('happy-family:status', function (): void {
    $this->info('SMVS Happy Family Portal foundation is installed.');
})->purpose('Show project foundation status');

Artisan::command('happy-family:inactivity-check', function (InactivityService $service): void {
    $result = $service->checkAll();
    $this->info(sprintf('Inactivity check complete. Reminders created: %d; alerts created: %d.', $result['reminders'], $result['alerts']));
})->purpose('Create 4-day inactivity reminders and 7-day inactivity alerts');

Schedule::command('happy-family:inactivity-check')->hourly()->withoutOverlapping();

// Keep the failed-job table bounded so a long-running production instance does not grow indefinitely.
Schedule::command('queue:prune-failed --hours=720')->dailyAt('02:20')->withoutOverlapping();
