<?php

namespace Tests\Feature;

use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\CompanyEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * InfoDot decides which platforms a company holds (R-50). This app honours it twice: at sign-in, from what the
 * redeem answer says of each company, and during a session, by asking InfoDot's read endpoint about the current
 * company. The bodies below are the shapes InfoDot's own tests assert.
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const READ = 'https://infodot.test/api/ecosystem/companies/*';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const OWN = '33333333-3333-4333-8333-333333333333';

    private string $bearer;

    /** Calls made to the read endpoint, counted where they are answered (a faked connection error is not recorded). */
    private int $reads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bearer = Str::random(40);

        config([
            'ecosystem.infodot.redeem_url' => self::REDEEM,
            'ecosystem.infodot.platform' => 'tasks',
            'ecosystem.infodot.timeout' => 2,
            'ecosystem.infodot.provision' => true,
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
            'ecosystem.entitlement.check' => true,
            'ecosystem.entitlement.url' => null,
            // The header switcher (dot-ui) reads the same InfoDot endpoint on its own cache key. It is pointed at a separate
            // faked address here so these tests keep counting only the entitlement client's reads.
            'ecosystem.infodot.companies_url' => 'https://infodot.test/switcher-read',
        ]);
        config()->set('ecosystem.infodot.'.'secret', $this->bearer);
    }

    // ---- fixtures -----------------------------------------------------------------------------------------------

    /** One company as InfoDot's redeem answer lists it. Leave $entitled null for an InfoDot from before R-50. */
    private function listed(string $uuid, string $name, ?bool $entitled = true, ?string $access = null, string $role = 'owner', bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role, 'infodot_role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $entitled ? null : $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        Http::swap(new Factory);
        Http::fake([self::REDEEM => Http::response([
            'platform' => 'tasks',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], ['Origin' => 'https://infodot.test']);
    }

    /** The read endpoint's answer for a company, with this platform's own row as given. */
    private function platforms(string $uuid, ?array $own = ['status' => 'included', 'entitled' => true, 'is_core' => true]): array
    {
        return [
            'platform' => 'tasks',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => array_values(array_filter([
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                $own === null ? null : ['key' => 'tasks', 'name' => 'Dot.Tasks'] + $own + ['access_mode' => 'everyone'],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
            ])),
        ];
    }

    private function refusedRow(string $status = 'suspended'): array
    {
        return ['status' => $status, 'entitled' => false, 'is_core' => false];
    }

    /** A person whose current team is a company linked to InfoDot. */
    private function memberOfCompany(string $uuid = self::ACME, string $name = 'Acme Ltd'): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create(['user_id' => $user->id, 'name' => $name, 'personal_team' => false]);
        $team->forceFill(['infodot_team_uuid' => $uuid])->save();
        $user->switchTeam($team);

        return $user->fresh();
    }

    private function infodotSays(array|callable $byUuid): void
    {
        Http::swap(new Factory);
        Http::fake(['https://infodot.test/switcher-read/*' => Http::response('', 503), self::READ => function (Request $request) use ($byUuid) {
            $this->reads++;
            $uuid = Str::between($request->url(), '/companies/', '/platforms');
            $answer = is_callable($byUuid) ? $byUuid($uuid) : ($byUuid[$uuid] ?? Http::response(['error' => 'not_found'], 404));

            return is_array($answer) ? Http::response($answer) : $answer;
        }]);
    }

    private function readCalls(): int
    {
        return $this->reads;
    }

    // ---- at sign-in ---------------------------------------------------------------------------------------------

    public function test_an_entitled_company_is_admitted_and_synced(): void
    {
        $this->signIn('ann@acme.test', [$this->listed(self::ACME, 'Acme Ltd')], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Acme Ltd', $team->name);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_that_does_not_hold_the_platform_is_not_created_and_cannot_be_current(): void
    {
        $this->signIn('ann@acme.test', [
            $this->listed(self::ACME, 'Acme Ltd'),
            $this->listed(self::GLOBEX, 'Globex', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', self::GLOBEX)->first());
        $this->assertNotNull($acme = Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertNotSame(null, $user->current_team_id);
        $this->assertNull($user->currentTeam->infodot_team_uuid === self::GLOBEX ? 'globex' : null);
        $this->assertTrue($user->belongsToTeam($acme));
    }

    public function test_a_company_that_lost_the_platform_is_left_untouched_and_the_person_is_moved_off_it(): void
    {
        // First sign-in: both companies hold the platform, and Globex is the active one.
        $this->signIn('ann@acme.test', [$this->listed(self::ACME, 'Acme Ltd'), $this->listed(self::GLOBEX, 'Globex')], self::GLOBEX);
        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $globex = Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail();
        $this->assertSame($globex->id, $user->current_team_id);

        // A colleague who belongs to Globex here.
        $colleague = User::factory()->withPersonalTeam()->create(['email' => 'bo@globex.test']);
        $globex->users()->attach($colleague->id, ['role' => 'editor']);

        // Next sign-ins: Globex no longer holds it. InfoDot still names it the active company and gives it a new name.
        $this->signIn('ann@acme.test', [$this->listed(self::ACME, 'Acme Ltd'), $this->listed(self::GLOBEX, 'Globex Renamed', false, 'suspended')], self::GLOBEX);
        $this->signIn('bo@globex.test', [$this->listed(self::ACME, 'Acme Ltd', role: 'member'), $this->listed(self::GLOBEX, 'Globex Renamed', false, 'suspended', 'admin')], self::GLOBEX);

        $globex->refresh();
        $this->assertSame('Globex', $globex->name, 'not updated');
        $this->assertSame('editor', $globex->users()->whereKey($colleague->id)->first()->membership->role, 'role not changed');
        $this->assertTrue($colleague->fresh()->belongsToTeam($globex), 'not detached: the data and the membership stay');
        $this->assertSame(Team::where('infodot_team_uuid', self::ACME)->value('id'), $user->fresh()->current_team_id, 'moved to the company that holds it');
    }

    public function test_an_answer_without_the_key_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->listed(self::ACME, 'Acme Ltd', null),
            $this->listed(self::GLOBEX, 'Globex', null),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->value('id'), $user->current_team_id);
    }

    public function test_with_nothing_entitled_the_person_gets_the_plain_page_and_no_account(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->listed(self::OWN, "Ann's Team", false, 'no_personal_access', personal: true),
            $this->listed(self::ACME, 'Acme Ltd', false, 'not_activated'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Tasks is not switched on for your company')
            ->assertSee('Open Platforms in InfoDot')
            ->assertSee('href="https://infodot.test/platforms/tasks"', false)
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertSame(0, Team::count());
        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
    }

    public function test_the_sign_in_page_is_worded_from_the_reason(): void
    {
        $this->signIn('ann@acme.test', [$this->listed(self::ACME, 'Acme Ltd', false, 'payment_required')])
            ->assertStatus(403)->assertSee('Dot.Tasks needs a payment for your company');

        $this->signIn('ann@acme.test', [$this->listed(self::ACME, 'Acme Ltd', false, 'suspended')])
            ->assertStatus(403)->assertSee('Dot.Tasks is suspended for your company');

        $this->signIn('ann@acme.test', [$this->listed(self::ACME, 'Acme Ltd', false, 'expired')])
            ->assertStatus(403)->assertSee('Dot.Tasks has ended for your company');
    }

    /**
     * Dot.Tasks is in the free core: InfoDot marks a personal workspace `entitled: true` for it at redeem, and the
     * read endpoint says `included`. The personal workspace is not linked to a company here, so nothing is asked.
     */
    public function test_a_person_with_only_a_personal_workspace_keeps_their_inbox(): void
    {
        $this->infodotSays([]);
        $this->signIn('ann@acme.test', [$this->listed(self::OWN, "Ann's Team", true, personal: true)])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertTrue($user->currentTeam->personal_team);

        $inbox = $this->get('/inbox');
        $inbox->assertRedirect();
        $this->get($inbox->headers->get('Location'))->assertOk();
        $this->get('/dashboard')->assertOk();

        $this->assertSame(0, $this->readCalls());
    }

    public function test_a_personal_workspace_infodot_refuses_is_left_for_a_company_that_holds_the_platform(): void
    {
        $this->signIn('ann@acme.test', [
            $this->listed(self::OWN, "Ann's Team", false, 'no_personal_access', personal: true),
            $this->listed(self::ACME, 'Acme Ltd'),
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        $this->assertSame($acme->id, $user->current_team_id);

        // Going back to the personal workspace in this session shows the page, with the way out.
        $this->infodotSays([self::ACME => $this->platforms(self::ACME)]);
        $this->put(route('current-team.update'), ['team_id' => $user->personalTeam()->id])->assertRedirect();
        $this->get('/dashboard')->assertStatus(403)->assertSee('Dot.Tasks needs a company that has switched it on')->assertSee('Acme Ltd');

        $this->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->get('/dashboard')->assertOk();
    }

    public function test_the_personal_answer_is_remembered_in_the_session_true_false_or_absent(): void
    {
        $key = CompanyEntitlement::PERSONAL_SESSION_KEY;

        $this->signIn('ann@acme.test', [$this->listed(self::OWN, "Ann's Team", true, personal: true)]);
        $this->assertTrue(session($key));

        $this->signIn('bo@acme.test', [$this->listed(self::OWN, "Bo's Team", false, 'no_personal_access', personal: true), $this->listed(self::ACME, 'Acme Ltd')]);
        $this->assertFalse(session($key));

        // An older InfoDot gives no key: nothing is remembered, and a stale answer is not carried over.
        $this->signIn('cy@acme.test', [$this->listed(self::OWN, "Cy's Team", null, personal: true)]);
        $this->assertNull(session($key));
    }

    public function test_remembered_false_refuses_the_personal_workspace_and_any_team_without_an_infodot_company(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $local = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false, 'name' => 'Local only']);
        TaskList::create(['team_id' => $user->personalTeam()->id, 'owner_id' => $user->id, 'name' => 'My private board']);
        $this->infodotSays([]);

        foreach ([$user->personalTeam(), $local] as $team) {
            $user->switchTeam($team);
            $this->withSession([CompanyEntitlement::PERSONAL_SESSION_KEY => false])
                ->actingAs($user->fresh())->get('/dashboard')
                ->assertStatus(403)
                ->assertSee('Dot.Tasks needs a company that has switched it on')
                ->assertSee('href="https://infodot.test/platforms/tasks"', false)
                ->assertDontSee('My private board');
        }

        $this->assertDatabaseHas('task_lists', ['name' => 'My private board']);
        $this->assertSame(0, $this->readCalls(), 'a team with no InfoDot company is never asked about');
    }

    public function test_remembered_true_or_nothing_remembered_keeps_the_personal_inbox(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->infodotSays([]);

        $this->withSession([CompanyEntitlement::PERSONAL_SESSION_KEY => true])->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/inbox')->assertRedirect();

        $this->assertSame(0, $this->readCalls());
    }

    public function test_a_refused_personal_workspace_still_offers_the_entitled_company_and_exempt_routes_stay_open(): void
    {
        $user = $this->memberOfCompany();
        $personal = $user->personalTeam();
        $user->switchTeam($personal);
        $this->infodotSays([self::ACME => $this->platforms(self::ACME)]);

        $session = [CompanyEntitlement::PERSONAL_SESSION_KEY => false];
        $this->withSession($session)->actingAs($user->fresh())->get('/dashboard')
            ->assertStatus(403)->assertSee('Acme Ltd')->assertDontSee('Your own workspace');

        $this->withSession($session)->actingAs($user->fresh())->get('/cookies')->assertOk();
        $this->withSession($session)->actingAs($user->fresh())->post('/logout')->assertRedirect();
    }

    // ---- during a session ---------------------------------------------------------------------------------------

    public function test_a_company_that_holds_the_platform_carries_on(): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME)]);

        $this->actingAs($user)->get('/dashboard')->assertOk();

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && str_contains($request->url(), 'platform=tasks')
            && $request->hasHeader('X-Dot-Platform', 'tasks')
            && $request->hasHeader('Authorization', 'Bearer '.$this->bearer));
    }

    public function test_a_company_that_no_longer_holds_it_gets_a_403_with_none_of_its_data(): void
    {
        $user = $this->memberOfCompany();
        TaskList::create(['team_id' => $user->current_team_id, 'owner_id' => $user->id, 'name' => 'Shaft seven overhaul']);
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow())]);

        foreach (['/dashboard', '/my-tasks', '/reports', '/user/profile'] as $path) {
            $this->actingAs($user)->get($path)
                ->assertStatus(403)
                ->assertSee('Dot.Tasks is suspended for Acme Ltd')
                ->assertSee('href="https://infodot.test/platforms/tasks"', false)
                ->assertDontSee('Shaft seven overhaul')
                ->assertDontSee($user->email)
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        // Nothing is changed either: a write is refused before it runs, and Livewire's endpoint with it.
        $this->actingAs($user)->post('/lists', ['name' => 'Written while refused'])->assertStatus(403);
        $this->actingAs($user)->post('/livewire/update', [])->assertStatus(403);
        $this->assertDatabaseMissing('task_lists', ['name' => 'Written while refused']);
        $this->assertDatabaseHas('task_lists', ['name' => 'Shaft seven overhaul']);
    }

    #[DataProvider('statuses')]
    public function test_the_page_is_worded_from_the_status(string $status, string $heading): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow($status))]);

        $response = $this->actingAs($user)->get('/dashboard')->assertStatus(403)->assertSee($heading);

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
    }

    public static function statuses(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Tasks is not switched on for Acme Ltd'],
            'pending' => ['activation_pending', 'Dot.Tasks is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Tasks is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Tasks needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Tasks has ended for Acme Ltd'],
            'a status this app has never seen' => ['something_new', 'Dot.Tasks is not switched on for Acme Ltd'],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_at_infodot_carries_on_and_is_logged_once(callable $answer): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays(fn (string $uuid) => $answer($uuid));
        Log::spy();

        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee('Open Platforms in InfoDot');
        $this->actingAs($user)->get('/my-tasks')->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_starts_with($message, 'InfoDot entitlement check gave no answer'))->once();
        $this->assertSame(1, $this->readCalls(), 'the failure is remembered for its minute');
    }

    public static function failures(): array
    {
        $row = ['key' => 'tasks', 'name' => 'Dot.Tasks', 'status' => 'suspended', 'is_core' => false];

        return [
            'connection error or timeout' => [fn () => throw new ConnectionException('timed out')],
            '401 secret refused' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            '403 platform switched off' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            '404 unknown company' => [fn () => Http::response(['error' => 'not_found'], 404)],
            '500' => [fn () => Http::response('Server Error', 500)],
            '503' => [fn () => Http::response('', 503)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn (string $uuid) => Http::response(['platform' => 'tasks', 'company' => ['uuid' => $uuid, 'personal' => false]])],
            'own row missing' => [fn (string $uuid) => Http::response(['platform' => 'tasks', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => [['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false]]])],
            'own row without a boolean' => [fn (string $uuid) => Http::response(['platform' => 'tasks', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => [$row + ['entitled' => 'no']]])],
            'another company in the answer' => [fn () => Http::response(['platform' => 'tasks', 'company' => ['uuid' => self::GLOBEX, 'personal' => false], 'platforms' => [$row + ['entitled' => false]]])],
        ];
    }

    public function test_no_secret_carries_on_without_a_call(): void
    {
        $user = $this->memberOfCompany();
        config()->set('ecosystem.infodot.'.'secret', null);
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow())]);
        Log::spy();

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->assertSame(0, $this->readCalls());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_starts_with($message, 'InfoDot entitlement check gave no answer'))->once();
    }

    public function test_the_answer_is_cached_per_company_for_ten_minutes(): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME)]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->get('/my-tasks')->assertOk();
        $this->assertSame(1, $this->readCalls());

        $this->travel(9)->minutes();
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->assertSame(1, $this->readCalls());

        // After the window InfoDot is asked again, and a changed answer takes effect.
        $this->travel(2)->minutes();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow('payment_required'))]);
        $this->actingAs($user)->get('/dashboard')->assertStatus(403)->assertSee('Dot.Tasks needs a payment for Acme Ltd');
        $this->assertSame(2, $this->readCalls());
    }

    public function test_a_failure_is_forgotten_after_a_minute(): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays(fn () => Http::response('', 503));

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->travel(61)->seconds();

        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow())]);
        $this->actingAs($user)->get('/dashboard')->assertStatus(403);
    }

    public function test_with_the_flag_off_nothing_is_asked(): void
    {
        config(['ecosystem.entitlement.check' => false]);
        $user = $this->memberOfCompany();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow())]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->actingAs($user)->post('/livewire/update', []);

        $this->assertSame(0, $this->readCalls());
    }

    public function test_the_flag_is_on_unless_the_environment_says_otherwise(): void
    {
        $config = file_get_contents(config_path('ecosystem.php'));

        $this->assertStringContainsString("env('TASKS_ENTITLEMENT_CHECK', true)", $config);
        $this->assertStringContainsString('TASKS_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_exempt_routes_ask_nothing_and_stay_open_to_a_refused_company(): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow())]);

        // Public pages, health checks, the machine API and the handoff receiver, signed in.
        foreach (['/', '/cookies', '/terms-of-service', '/privacy-policy', '/up', '/ready', '/up/scheduler', '/up/realtime', '/auth/ecosystem'] as $path) {
            $status = $this->actingAs($user)->get($path)->getStatusCode();
            $this->assertNotSame(403, $status, $path);
        }

        $this->actingAs($user)->post('/csp-report', [])->assertStatus(204);
        $this->actingAs($user)->post(route('ecosystem.auth'), ['token' => 'x'])->assertRedirect('/login');
        $this->assertSame(0, $this->readCalls());

        // Sign-out works for a refused person.
        $this->actingAs($user)->post('/logout')->assertRedirect();
        $this->assertGuest();
        $this->assertSame(0, $this->readCalls());

        // Signed out, nothing is asked anywhere.
        $this->get('/login')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/livewire/update', []);
        $this->assertSame(0, $this->readCalls());
    }

    public function test_the_machine_api_is_left_alone(): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow())]);

        $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertOk();

        $this->assertSame(0, $this->readCalls());
    }

    public function test_a_team_that_is_not_an_infodot_company_asks_nothing(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $local = Team::factory()->create(['user_id' => $user->id, 'personal_team' => false]);
        $this->infodotSays([]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
        $user->switchTeam($local);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();

        $this->assertSame(0, $this->readCalls());
    }

    public function test_a_second_company_that_holds_the_platform_can_still_be_switched_to(): void
    {
        $user = $this->memberOfCompany();
        $globex = Team::factory()->create(['user_id' => $user->id, 'name' => 'Globex', 'personal_team' => false]);
        $globex->forceFill(['infodot_team_uuid' => self::GLOBEX])->save();
        TaskList::create(['team_id' => $globex->id, 'owner_id' => $user->id, 'name' => 'Globex board']);

        $this->infodotSays([
            self::ACME => $this->platforms(self::ACME, $this->refusedRow('subscription_expired')),
            self::GLOBEX => $this->platforms(self::GLOBEX),
        ]);

        // The page names the person's other workspaces, by name only, each as a way out.
        $this->actingAs($user)->get('/dashboard')
            ->assertStatus(403)
            ->assertSee('Dot.Tasks has ended for Acme Ltd')
            ->assertSee('Globex')
            ->assertSee('Your own workspace')
            ->assertDontSee('Globex board');

        $this->actingAs($user)->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $user->fresh()->current_team_id);
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertDontSee('Open Platforms in InfoDot');

        // And back again is refused again, from the cache: one call per company.
        $this->actingAs($user->fresh())->put(route('current-team.update'), ['team_id' => Team::where('infodot_team_uuid', self::ACME)->value('id')]);
        $this->actingAs($user->fresh())->get('/dashboard')->assertStatus(403);
        $this->assertSame(2, $this->readCalls());
    }

    public function test_the_cache_holds_the_answer_only(): void
    {
        $user = $this->memberOfCompany();
        $this->infodotSays([self::ACME => $this->platforms(self::ACME, $this->refusedRow())]);

        $this->actingAs($user)->get('/dashboard')->assertStatus(403);

        $this->assertSame(['answer' => ['entitled' => false, 'status' => 'suspended']], Cache::get('infodot:entitlement:tasks:'.self::ACME));
    }
}
