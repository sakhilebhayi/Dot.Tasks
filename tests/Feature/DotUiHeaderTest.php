<?php

namespace Tests\Feature;

use App\Livewire\Tasks\FindTasks;
use App\Livewire\Tasks\HeaderSearch;
use App\Models\Task;
use App\Models\TaskList;
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
 * The header is the shared Dot header (dot-ui 0.5.0): the search pill (typed into, with a real dropdown), the platform
 * switcher, the bell and the account menu.
 *
 * The header search is a Livewire component (HeaderSearch) over the same query as the find palette (Ctrl K, FindTasks): it
 * keeps that logic, its reading of a question and its team scope. The switcher only ever shows what InfoDot says the CURRENT
 * company holds, linking through InfoDot's /go (never straight to a host, never with a token).
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

    // ── The search pill (a real, typed search) ──────────────────────────────

    /** The markup of the header search only. */
    private function pillHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dh-search" id="dh-search".*?<button type="button" class="dh-icon-btn dh-search__close"/s', $html, $m), 'the header holds the search column');

        return $m[0];
    }

    public function test_the_header_search_is_the_shared_pill_and_takes_text(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();
        $pill = $this->pillHtml($html);

        $this->assertStringContainsString('class="dsr-input"', $pill, 'the shared pill');
        $this->assertStringNotContainsString('readonly', $pill, 'it takes text now');
        $this->assertStringContainsString('wire:model.live.debounce.400ms="query"', $pill);
        $this->assertStringContainsString('placeholder="Find a task"', $pill);
        $this->assertStringContainsString('data-find-pill', $pill);
        $this->assertStringContainsString('data-dsr-escape-clear', $pill, 'Escape empties the box, as InfoDot\'s does');
        $this->assertStringNotContainsString('dsr-kbd', $pill, 'Ctrl K belongs to the palette, so the pill shows no hint and claims no shortcut');
        $this->assertStringNotContainsString('data-dsr-shortcut', $pill);
        $this->assertStringNotContainsString('data-open-search', $pill);
        // The empty box offers the places to go and the team\'s boards, nothing invented.
        $this->assertStringContainsString('Go to', $pill);
        // The old palette is still on the page, unchanged.
        $this->assertStringContainsString('find-tasks', $html);
    }

    public function test_the_header_search_is_on_every_signed_in_page_type(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');
        $board = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Ops']);

        foreach (['dashboard' => route('dashboard'), 'my tasks' => route('tasks.mine'), 'my day' => route('tasks.today'), 'upcoming' => route('tasks.upcoming'),
            'board' => route('task-lists.show', $board), 'reports' => route('reports.index'), 'labels' => route('labels.index'), 'templates' => route('tasks.templates'),
            'removed' => route('removed.index'), 'notifications' => route('notifications.index'), 'profile' => route('profile.show'),
            'team settings' => route('teams.show', $user->currentTeam->id)] as $name => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('id="dsr-find"', $html, $name.': the header search is present');
            $this->assertStringContainsString('data-dh-search-toggle', $html, $name.': and its phone button');
        }
    }

    public function test_the_header_search_finds_tasks_of_this_team_only(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME)), self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX))]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');
        $mine = TaskList::create(['team_id' => $acme->currentTeam->id, 'owner_id' => $acme->id, 'name' => 'Acme ops']);
        $theirs = TaskList::create(['team_id' => $globex->currentTeam->id, 'owner_id' => $globex->id, 'name' => 'Globex ops']);
        Task::create(['task_list_id' => $mine->id, 'creator_id' => $acme->id, 'title' => 'Order forklift batteries', 'status' => 'todo']);
        Task::create(['task_list_id' => $theirs->id, 'creator_id' => $globex->id, 'title' => 'Order forklift tyres', 'status' => 'todo']);

        Livewire::actingAs($acme)->test(HeaderSearch::class)
            ->set('query', 'forklift')
            ->assertSee('Order forklift batteries')
            ->assertDontSee('Order forklift tyres')
            ->assertDontSee('Globex ops');
        Livewire::actingAs($globex)->test(HeaderSearch::class)
            ->set('query', 'forklift')
            ->assertSee('Order forklift tyres')
            ->assertDontSee('Order forklift batteries');
    }

    public function test_the_header_search_never_shows_someone_elses_inbox_or_a_board_of_another_team(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');
        $teammate = User::factory()->create();
        $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);
        $teammate->switchTeam($owner->currentTeam);

        $inbox = TaskList::create(['team_id' => $owner->currentTeam->id, 'owner_id' => $owner->id, 'name' => 'Inbox', 'is_inbox' => true]);
        Task::create(['task_list_id' => $inbox->id, 'creator_id' => $owner->id, 'title' => 'Private salary note', 'status' => 'todo']);
        $outside = Team::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'personal_team' => false]);
        TaskList::create(['team_id' => $outside->id, 'owner_id' => $outside->user_id, 'name' => 'Salary board of another team']);

        Livewire::actingAs($owner)->test(HeaderSearch::class)->set('query', 'salary')->assertSee('Private salary note')->assertDontSee('another team');
        Livewire::actingAs($teammate->fresh())->test(HeaderSearch::class)->set('query', 'salary')->assertDontSee('Private salary note')->assertDontSee('another team')->assertSee('Nothing matches');
    }

    public function test_the_header_search_reads_a_question_like_the_palette_and_shows_what_it_understood(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');
        $board = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Ops']);
        Task::create(['task_list_id' => $board->id, 'creator_id' => $user->id, 'title' => 'Late filing', 'status' => 'todo', 'due_date' => now()->subDays(3)]);
        Task::create(['task_list_id' => $board->id, 'creator_id' => $user->id, 'title' => 'Future filing', 'status' => 'todo', 'due_date' => now()->addDays(20)]);

        Livewire::actingAs($user)->test(HeaderSearch::class)
            ->set('query', 'overdue filing')
            ->assertSee('Overdue')
            ->assertSee('Late filing')
            ->assertDontSee('Future filing');
        // The same answer from the palette.
        Livewire::actingAs($user)->test(FindTasks::class)->dispatch('open-search')->set('query', 'overdue filing')->assertSee('Late filing')->assertDontSee('Future filing');
    }

    public function test_the_header_search_has_empty_and_no_match_states_and_waits_for_two_letters(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');
        $board = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Ops']);
        Task::create(['task_list_id' => $board->id, 'creator_id' => $user->id, 'title' => 'Xylophone tuning', 'status' => 'todo']);

        Livewire::actingAs($user)->test(HeaderSearch::class)
            ->assertSee('Go to')->assertSee('Ops')->assertSee('Type two letters')
            ->set('query', 'x')->assertDontSee('Xylophone tuning')->assertSee('Type two letters')
            ->set('query', 'zzzzqq')->assertSee('Nothing matches')
            ->set('query', 'xylo')->assertSee('Xylophone tuning');
    }

    public function test_the_header_search_finds_boards_by_name_and_escapes_like_characters(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');
        TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Warehouse']);
        TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Fleet']);

        Livewire::actingAs($user)->test(HeaderSearch::class)->set('query', 'ware')->assertSee('Warehouse')->assertDontSee('Fleet');
        // A percent sign is a character, not a wildcard.
        Livewire::actingAs($user)->test(HeaderSearch::class)->set('query', '%%')->assertDontSee('Warehouse')->assertDontSee('Fleet')->assertSee('Nothing matches');
    }

    public function test_enter_in_the_header_search_opens_the_first_task_or_goes_to_the_first_board(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');
        $board = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Warehouse']);
        $task = Task::create(['task_list_id' => $board->id, 'creator_id' => $user->id, 'title' => 'Count pallets', 'status' => 'todo']);

        Livewire::actingAs($user)->test(HeaderSearch::class)->set('query', 'pallets')->call('go')->assertDispatched('open-task', taskId: $task->id)->assertSet('query', '');
        Livewire::actingAs($user)->test(HeaderSearch::class)->set('query', 'warehouse')->call('go')->assertRedirect(route('task-lists.show', $board));
        Livewire::actingAs($user)->test(HeaderSearch::class)->set('query', 'nothinghere')->call('go')->assertNotDispatched('open-task')->assertNoRedirect();
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

        foreach (['css/dot-roboto.css', 'css/dot-type.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher.css', 'css/dot-header.css', 'js/dot-search.js', 'js/dot-switcher.js', 'js/dot-nav.js'] as $asset) {
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
        $this->assertEqualsCanonicalizing(['https://ui-avatars.com'], $origins[0], 'no Google Fonts origin: Roboto is served from this site');

        // The script tag carries the request's nonce, as every other script on the page does.
        $this->assertMatchesRegularExpression('/<script src="[^"]*dot-switcher\.js" defer nonce="[^"]+"><\/script>/', $html);
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent();

        foreach ([$this->pillHtml($html), $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }
}
