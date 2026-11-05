<?php

namespace Tests\Feature;

use App\Http\Controllers\SchedulerProbeController;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RateLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_signed_in_route_has_a_limit(): void
    {
        $bare = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteItem $route) => in_array('verified', $route->gatherMiddleware(), true))
            ->reject(fn (RouteItem $route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')))
            ->map(fn (RouteItem $route) => $route->uri())->values()->all();

        $this->assertSame([], $bare);
    }

    public function test_the_app_limit_answers_429_when_it_is_reached(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        RateLimiter::for('app', fn () => Limit::perMinute(2));

        $this->actingAs($user);
        $this->get('/my-tasks')->assertOk();
        $this->get('/my-tasks')->assertOk();
        $this->get('/my-tasks')->assertStatus(429);
    }

    public function test_livewire_round_trips_have_their_own_limit(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn (RouteItem $r) => $r->uri() === 'livewire/update');

        $this->assertContains('throttle:livewire', $route->gatherMiddleware());
    }

    public function test_ready_says_ok_and_names_no_host(): void
    {
        $this->getJson('/ready')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database.pending_migrations', 0);
    }

    public function test_ready_fails_when_the_scheduler_has_stopped(): void
    {
        Cache::forever(SchedulerProbeController::SCHEDULER_KEY, now()->subDay()->toIso8601String());

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('checks.scheduler.ok', false);
    }
}
