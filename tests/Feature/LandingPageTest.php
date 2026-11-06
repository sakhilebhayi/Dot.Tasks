<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

/** The landing page's buttons lead somewhere a new person can start. */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_with_sign_up_closed_create_account_goes_to_infodot_and_says_so(): void
    {
        config(['fortify.features' => array_values(array_diff(config('fortify.features'), [Features::registration()]))]);

        $page = $this->get('/')->assertOk()->assertSee('Accounts are made on InfoDot')->getContent();

        $this->assertSame(3, substr_count($page, 'href="https://infodot.co.za/register"'));
        $this->assertStringNotContainsString(url('/register'), $page);
    }

    public function test_with_sign_up_open_create_account_stays_here(): void
    {
        config(['fortify.features' => [...config('fortify.features'), Features::registration()]]);

        $this->get('/')->assertSee('href="'.route('register').'"', false)->assertDontSee('Accounts are made on InfoDot');
    }

    public function test_someone_signed_in_is_taken_to_the_app(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/')
            ->assertSee('Open Dot.Tasks')->assertDontSee('Create account')->assertDontSee('Accounts are made on InfoDot');
    }
}
