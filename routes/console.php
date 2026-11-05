<?php

use App\Http\Controllers\SchedulerProbeController;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Heartbeats that /up/scheduler and the Guardian read: the first proves cron fires, the second that a worker drains jobs.
Schedule::call(fn () => Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('queue-heartbeat');

// Dot.Tasks scheduled tasks
Schedule::command('tasks:check-due-soon')->dailyAt('07:00');
Schedule::command('tasks:detect-escalation-candidates')->dailyAt('07:30');

// Publish each company's daily aggregate numbers to Dot.Analytics (nothing happens until ANALYTICS_INGEST_URL and
// ANALYTICS_INGEST_SECRET are both set). Quiet hour, UTC, because the snapshot is dated by its UTC day.
Schedule::command('tasks:publish-metrics')->dailyAt('02:10')->timezone('UTC')->withoutOverlapping();

// Delete for good what has been on the Removed page longer than REMOVED_RETENTION_DAYS (default 90).
// `php artisan tasks:purge-removed --dry-run` reports without deleting.
Schedule::command('tasks:purge-removed')->dailyAt('03:10')->timezone('UTC')->withoutOverlapping();
