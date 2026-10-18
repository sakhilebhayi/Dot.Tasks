<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RealtimeHealthController;
use App\Http\Controllers\TaskListController;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Jetstream\Jetstream;

Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->name('ecosystem.auth');

// Real-time infrastructure health check -- unauthenticated like Laravel's
// own /up, for uptime monitors/load balancers.
Route::get('/up/realtime', [RealtimeHealthController::class, 'check'])
    ->name('health.realtime');

Route::get('/', fn () => view('welcome'));

// Cookie Policy — Jetstream's termsAndPrivacyPolicy feature covers terms.show/policy.show
// natively (registered at /terms-of-service and /privacy-policy, reading resources/markdown/
// terms.md and policy.md). There's no Jetstream equivalent for a Cookie Policy, so this one is
// wired by hand, following the exact same Markdown-source convention.
Route::get('/cookies', function () {
    return view('cookies', [
        'cookies' => Str::markdown(file_get_contents(Jetstream::localizedMarkdownPath('cookies.md'))),
    ]);
})->name('cookies');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        // A user removed from their last team (or who never completed team
        // setup) reaches this route with currentTeam genuinely null — the
        // auth:sanctum/verified middleware group above only checks that the
        // user is authenticated, not that they belong to a team. Without
        // this guard, $team->taskLists() below throws "Attempt to read
        // property 'id' on null".
        $team = auth()->user()->currentTeam;

        if (! $team) {
            return redirect()->route('teams.create');
        }

        $lists = $team
            ->taskLists()
            ->withCount('tasks')
            ->orderBy('sort_order')
            ->get();

        $teamTasks = fn () => Task::forTeam($team);

        $taskCounts = [
            'todo' => $teamTasks()->where('status', 'todo')->count(),
            'in_progress' => $teamTasks()->where('status', 'in_progress')->count(),
            'done' => $teamTasks()->where('status', 'done')->count(),
            'due_today' => $teamTasks()->whereDate('due_date', Carbon::today())->where('status', '!=', 'done')->count(),
            'overdue' => $teamTasks()->whereDate('due_date', '<', Carbon::today())->where('status', '!=', 'done')->count(),
            'completed_week' => $teamTasks()->where('status', 'done')->where('updated_at', '>=', Carbon::now()->startOfWeek())->count(),
        ];

        return view('dashboard', compact('lists', 'taskCounts'));
    })->name('dashboard');

    Route::get('/today', fn () => view('tasks.today'))->name('tasks.today');
    Route::get('/upcoming', fn () => view('tasks.upcoming'))->name('tasks.upcoming');

    Route::get('/lists/create', fn () => view('task-lists.create'))->name('task-lists.create');
    Route::post('/lists', [TaskListController::class, 'store'])->name('task-lists.store');
    Route::get('/lists/{taskList}', [TaskListController::class, 'show'])->name('task-lists.show');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    Route::get('/escalations', fn () => view('escalations.index'))->name('escalations.index');
});
