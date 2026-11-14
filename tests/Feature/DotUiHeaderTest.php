<?php

namespace Tests\Feature;

use App\Livewire\Tasks\FindTasks;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The header carries the shared Dot UI (dot-ui): the search pill and the platform switcher.
 *
 * The header "search" here is the trigger of the find palette (Livewire FindTasks): a button, not a search form. It
 * keeps that job: the pill is a read-only box that opens the palette, which keeps its own input, its reading of a
 * question and its team scope. The switcher only ever shows what InfoDot says the CURRENT company holds, linking through
 * InfoDot's /go (never straight to a host, never with a token).
 */
class DotUiHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'tasks', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
            'ecosystem.entitlement.check' => true,
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['tasks', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['tasks' => 'Dot.Tasks', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'tasks', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::factory()->create(['user_id' => $user->id, 'name' => $name, 'personal_team' => false]);
        $team->forceFill(['infodot_team_uuid' => $uuid])->save();
        $user->switchTeam($team);

        return $user->fresh();
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the header carries the switcher');

        return $m[0];
    }

    // ── The search pill (the palette's trigger) ─────────────────────────────

    public function test_the_header_pill_is_the_palette_trigger_and_posts_nowhere(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div class="topbar-search">.*?<\/form>/s', $html, $pill), 'the pill sits in the header slot');
        $this->assertStringContainsString('class="dsr-input"', $pill[0], 'the shared pill');
        $this->assertStringContainsString('readonly', $pill[0], 'it never takes text: the palette holds the real input');
        $this->assertStringContainsString('data-open-search', $pill[0], 'the same hook the old button used');
        $this->assertStringContainsString('placeholder="Find a task"', $pill[0]);
        $this->assertStringContainsString('Ctrl K', $pill[0]);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*\saction=/', $pill[0], 'it submits nowhere');
        $this->assertStringNotContainsString('class="find"', $html, 'the old button is gone');
        // The palette itself is still on the page, unchanged.
        $this->assertStringContainsString('find-tasks', $html);
    }

    public function test_the_palette_opens_and_reads_questions_exactly_as_before(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');

        Livewire::actingAs($user)->test(FindTasks::class)
            ->assertSet('open', false)
            ->dispatch('open-search')
            ->assertSet('open', true)
            ->assertSee('Find a task, or ask: overdue maintenance for James');
    }

    public function test_a_guest_sees_no_search_and_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('dsr-input', false)->assertDontSee('data-dsw', false);
    }

    public function test_the_in_page_filters_are_the_shared_compact_filter_with_their_properties_kept(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');

        $mine = $this->actingAs($user)->get(route('tasks.mine'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*>/', $mine);
        $this->assertStringContainsString('aria-label="Search my tasks"', $mine);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $mine);

        $removed = $this->actingAs($user)->get(route('removed.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*\bclass="dsr-filter"[^>]*>/', $removed);
        $this->assertStringContainsString('id="removed-search"', $removed);
        $this->assertStringContainsString('maxlength="100"', $removed);
        $this->assertSame(1, substr_count($removed, 'role="search"'), 'only the header pill is a search landmark');
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="tasks"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');

        // Current platform: a marker, not a link.
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Tasks', $here[0]);

        // Held platforms are links through InfoDot, with this company; InfoDot itself goes home.
        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        // Not held: not a tile, only in the "not switched on" list that points at InfoDot's explanation.
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);

        // Never a token, never a secret, never straight to a destination host.
        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();

        Http::assertSent(function (OutgoingRequest $request) {
            return str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
                && $request->hasHeader('Authorization', 'Bearer a-test-secret')
                && $request->hasHeader('X-Dot-Platform', 'tasks');
        });
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['tasks', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['tasks', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get(route('dashboard'))->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_cannot_be_reached_the_switcher_says_so_and_never_shows_active(): void
    {
        // The entitlement check and the switcher both get a 503: the check carries on (as it always did), the switcher degrades.
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        $this->assertStringContainsString('/go/files', $panel, 'the core set still leaves through InfoDot, which decides again');
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_keeps_its_inbox_gets_the_honest_sentence_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        // The Inbox is the personal landing: it redirects to the person's inbox board, which renders with the header.
        $html = $this->followingRedirects()->actingAs($user)->get(route('tasks.inbox'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringContainsString('dsw-personal', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel, 'no platforms are listed for a workspace that is not a company');
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel, 'the way to InfoDot is kept');
        Http::assertNothingSent();
    }

    // ── CSP: self-hosted, nothing inline, nothing external ──────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $response = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk();
        $html = $response->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        // Every relative url() in the shared CSS lands on a file that is really there (fonts, arrow mask, stencil face).
        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        // Logos: this site's own copies, so strict img-src works.
        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }

        // The policy gained no origin.
        $policy = (string) $response->headers->get('Content-Security-Policy');
        preg_match_all('#https?://[^\s;]+#', $policy, $origins);
        $this->assertEqualsCanonicalizing(['https://fonts.googleapis.com', 'https://fonts.gstatic.com', 'https://ui-avatars.com'], $origins[0]);

        // The script tag carries the request's nonce, as every other script on the page does.
        $this->assertMatchesRegularExpression('/<script src="[^"]*dot-switcher\.js" defer nonce="[^"]+"><\/script>/', $html);
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('/<div class="topbar-search">.*?<\/form>/s', $html, $pill));
        foreach ([$pill[0], $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }
}
