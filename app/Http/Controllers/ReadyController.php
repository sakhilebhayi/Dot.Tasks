<?php

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Readiness for uptime monitors. /up only says PHP answers; /ready says Dot.Tasks can do its job: the database answers
 * with no migrations pending, the cache reads back what it wrote, storage is writable and the scheduler beat recently.
 * It names checks and ages, never a host, a credential, an error message or anyone's data.
 */
class ReadyController extends Controller
{
    public function __invoke(Migrator $migrator): JsonResponse
    {
        $checks = [
            'database' => $this->attempt(function () use ($migrator) {
                DB::select('select 1');
                $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
                $pending = array_diff($files, $migrator->getRepository()->getRan());

                return ['ok' => $pending === [], 'pending_migrations' => count($pending)];
            }),
            'cache' => $this->attempt(function () {
                $key = 'tasks:ready:'.Str::random(8);
                Cache::put($key, 'yes', 30);

                return ['ok' => Cache::pull($key) === 'yes'];
            }),
            'storage' => ['ok' => is_writable(storage_path('framework/cache')) && is_writable(storage_path('logs'))],
            'scheduler' => $this->attempt(fn () => $this->scheduler()),
        ];

        $ok = collect($checks)->every(fn (array $check) => $check['ok']);

        return response()
            ->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks, 'checked_at' => now()->toIso8601String()], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** @return array{ok: bool, last_beat_seconds_ago: int|null} */
    private function scheduler(): array
    {
        $beat = Cache::get(SchedulerProbeController::SCHEDULER_KEY);
        $age = $beat === null ? null : (int) Carbon::parse((string) $beat)->diffInSeconds(Carbon::now());

        // Nothing recorded yet (a fresh deploy or a cleared cache): unknown, not failed.
        return ['ok' => $age === null || $age <= SchedulerProbeController::SCHEDULER_STALE_SECONDS, 'last_beat_seconds_ago' => $age];
    }

    /**
     * @param  callable(): array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function attempt(callable $check): array
    {
        try {
            return $check();
        } catch (Throwable) {
            return ['ok' => false];
        }
    }
}
