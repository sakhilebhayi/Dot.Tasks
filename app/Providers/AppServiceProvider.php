<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // /up answered 200 whenever PHP booted, even with the database down. The framework dispatches DiagnosingHealth
        // before it renders the page: a failing query turns the answer into a 500 (the exception text is never shown).
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        $by = fn (Request $request) => $request->user()?->getAuthIdentifier() ?? $request->ip();
        // Every page and form of the signed-in app.
        RateLimiter::for('app', fn (Request $request) => Limit::perMinute(300)->by('app:'.$by($request)));
        // Livewire's round trips: every click and keystroke that talks to the server, and dragging is many of them.
        RateLimiter::for('livewire', fn (Request $request) => Limit::perMinute(900)->by('lw:'.$by($request)));

        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)->middleware(['web', 'throttle:livewire']));
    }
}
