<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * All scheduling lives here, driven by a single shared-hosting cron entry running
 * `php artisan schedule:run` every minute (docs/04_System_Architecture.md §5,
 * docs/14_Deployment.md §3). No persistent workers exist — the queue is drained in short
 * bursts each tick.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')->everyMinute();
Schedule::command('accounts:purge-unacknowledged')->hourly();

// Phase 3 will add here (docs/14_Deployment.md §3):
//   checkins:evaluate (every 5 minutes), sessions:cleanup (daily),
//   storage:recalculate-usage (hourly), backup:run (daily 02:00).
