<?php

use App\Http\Controllers\Auth\EcosystemAuthController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RealtimeHealthController;
use App\Http\Controllers\TaskListController;
use App\Models\TaskList;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Jetstream;

Route::post('/auth/ecosystem', [EcosystemAuthController::class, 'handle'])
    ->withoutMiddleware(['Illuminate\Foundation\Http\Middleware\PreventRequestForgery', 'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken', 'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken'])
    ->name('ecosystem.auth');

// Links minted before the token moved into a POST body carried it in the URL. They are no longer honoured:
// the visitor is sent to the normal sign-in page and the token in the URL is never read.
Route::get('/auth/ecosystem', [EcosystemAuthController::class, 'legacy']);

// Content-Security-Policy violation reports. Browsers post these without a CSRF token, and they carry no login, so the
// route is CSRF-exempt and session-less (no session row or cookie per report); throttled, and the controller keeps only
// the violated directive and the blocked host.
Route::post('/csp-report', CspReportController::class)
    ->withoutMiddleware([
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->middleware('throttle:60,1,csp-report')
    ->name('csp.report');

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
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/my-tasks', fn () => view('tasks.mine'))->name('tasks.mine');
    Route::get('/today', fn () => view('tasks.today'))->name('tasks.today');
    Route::get('/upcoming', fn () => view('tasks.upcoming'))->name('tasks.upcoming');

    Route::get('/lists/create', fn () => view('task-lists.create'))
        ->middleware('can:create,'.TaskList::class)
        ->name('task-lists.create');
    Route::post('/lists', [TaskListController::class, 'store'])->name('task-lists.store');
    Route::get('/lists/{taskList}', [TaskListController::class, 'show'])->name('task-lists.show');

    Route::get('/labels', fn () => view('labels.index'))->name('labels.index');

    // Deleted tasks: list and restore (admins and the owner restore)
    Route::get('/removed', fn () => view('removed.index'))->name('removed.index');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

    Route::get('/escalations', fn () => view('escalations.index'))->name('escalations.index');
});

// Public sign-up is closed (accounts are created by InfoDot at first sign-in). Keep the named route so any link or
// invitation email that points at it lands on the login page instead of failing.
if (! Features::enabled(Features::registration())) {
    Route::redirect('/register', '/login')->name('register');
}
