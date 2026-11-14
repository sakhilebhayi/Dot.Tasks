<?php

namespace Tests\Feature;

use App\Livewire\Landing;
use App\Livewire\Tasks\QuickAdd;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** What a person arriving from InfoDot lands on: the real logo, a welcome from their own records, and one action. */
class LandingExperienceTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    /** The logo files as the owner supplied them. A change here means the artwork was touched. */
    private const LOGOS = [
        'images/logo.png' => 'de78c57cbd097ac99e9f474295ade7afcc79161dba8c999eee7e757f680b8c07',
        'images/logo-light.png' => 'b3d1c990170911d3d2e4c4698d254384e8f8a5ddfcbcd941b95d60b8d431410b',
    ];

    /** Every platform key the ecosystem still has. */
    private const PLATFORMS = [
        'agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance',
        'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-05 09:00:00'); // A Thursday.
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function roles(): array
    {
        return ['owner' => ['owner'], 'admin' => ['admin'], 'editor' => ['editor'], 'viewer' => ['viewer']];
    }

    /** The landing block alone, as a person reads it: no markup, no attributes. */
    private function landingText(string $page): string
    {
        $this->assertSame(1, preg_match('/<section\b[^>]*\bclass="land".*?<\/section>/s', $page, $found));

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($found[0]), ENT_QUOTES)));
    }

    private function newMember(): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Mokoena']);
        $user->currentTeam->forceFill(['name' => 'Mokoena Plant Hire'])->save();

        return $user->fresh();
    }

    #[DataProvider('roles')]
    public function test_each_role_lands_on_one_primary_action_that_leads_to_a_working_page(string $role): void
    {
        $f = $this->teamFixture();

        $page = $this->actingAs($f->{$role})->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($page, 'data-primary-action'));
        $this->assertSame(1, preg_match('/<a class="btn primary land-go" href="([^"]+)" data-primary-action/', $page, $action));
        $this->assertSame(route('tasks.inbox'), $action[1]);
        // The bar's own New task button steps back here, so one filled button is on the page.
        $this->assertSame(1, substr_count($page, 'btn primary'));

        // Without scripts the link is followed: it ends on this person's own Inbox, where a task can be written.
        $this->followingRedirects()->get($action[1])->assertOk()->assertSee('Only you can see this.')->assertSee('Add task');
    }

    #[DataProvider('roles')]
    public function test_each_role_can_finish_the_task_the_primary_action_starts(string $role): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->{$role})->test(QuickAdd::class)
            ->dispatch('open-quick-add')
            ->assertSet('open', true)
            ->set('sentence', 'Book the crane inspection')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Added to your Inbox.');

        $task = Task::where('title', 'Book the crane inspection')->sole();
        $this->assertTrue($task->taskList->is_inbox);
        $this->assertSame($f->{$role}->id, $task->taskList->owner_id);
    }

    public function test_the_welcome_uses_the_first_name_and_the_company_from_real_records(): void
    {
        $text = $this->landingText($this->actingAs($this->newMember())->get(route('dashboard'))->assertOk()->getContent());

        $this->assertStringContainsString('Every job on a card.', $text);
        $this->assertStringContainsString('Welcome, Lerato. Dot.Tasks is where Mokoena Plant Hire writes work down', $text);
        $this->assertStringNotContainsString('Lerato Mokoena', $text);
    }

    public function test_the_original_logo_files_are_shown_untouched(): void
    {
        $page = $this->actingAs($this->newMember())->get(route('dashboard'))->assertOk()->getContent();

        foreach (self::LOGOS as $file => $hash) {
            $this->assertFileExists(public_path($file));
            $this->assertSame($hash, hash_file('sha256', public_path($file)), $file.' is not the original artwork');
            $this->assertStringContainsString('src="'.asset($file).'" alt="Dot.Tasks"', $page);
        }
    }

    public function test_a_new_member_with_nothing_sees_an_honest_empty_state_and_no_numbers(): void
    {
        $page = $this->actingAs($this->newMember())->get(route('dashboard'))->assertOk()->getContent();
        $text = $this->landingText($page);

        $this->assertStringContainsString('data-land-empty', $page);
        $this->assertStringContainsString('Nothing is on your name.', $text);
        $this->assertStringNotContainsString('data-land-count', $page);
        $this->assertStringNotContainsString('data-land-card', $page);
        $this->assertDoesNotMatchRegularExpression('/\d/', $text, 'A number is shown that no record backs');

        // Nothing is written down yet, so the panels of figures stay away and the boards say so plainly.
        $this->assertStringContainsString('data-boards-empty', $page);
        $this->assertStringContainsString('No boards yet.', $page);
        $this->assertStringNotContainsString('Arrange', $page);
        $this->assertSame(1, substr_count($page, 'data-primary-action'));
    }

    public function test_every_number_on_the_landing_comes_from_this_persons_records(): void
    {
        $f = $this->teamFixture();
        $mine = ['assignee_id' => $f->editor->id, 'status' => 'todo'];
        $f->list->tasks()->create($mine + ['title' => 'Order idler rollers', 'due_date' => '2026-11-18']);
        $f->list->tasks()->create($mine + ['title' => 'Service the gearbox', 'due_date' => '2026-11-01', 'priority' => 'urgent']);
        $f->list->tasks()->create(['assignee_id' => $f->editor->id, 'status' => 'done', 'title' => 'Finished already', 'completed_at' => now()]);
        $f->list->tasks()->create(['assignee_id' => $f->admin->id, 'status' => 'todo', 'title' => 'Somebody else holds this']);

        $page = $this->actingAs($f->editor)->get(route('dashboard'))->assertOk()->getContent();
        $text = $this->landingText($page);

        $this->assertSame(2, substr_count($page, 'data-land-card'));
        $this->assertStringContainsString('data-land-count>2<', $page);
        $this->assertStringNotContainsString('Finished already', $text);
        $this->assertStringNotContainsString('Somebody else holds this', $text);
        // Soonest due first.
        $this->assertLessThan(strpos($text, 'Order idler rollers'), strpos($text, 'Service the gearbox'));

        // Take away what the records put there (the count and the two due dates): no other figure is left.
        $rest = str_replace(['On your name 2', 'Nov 1 ', 'Nov 18 '], ' ', $text);
        $this->assertDoesNotMatchRegularExpression('/\d/', $rest, 'A number is shown that no record backs: '.$rest);
    }

    public function test_the_slot_shows_four_cards_and_counts_the_rest(): void
    {
        $f = $this->teamFixture();
        foreach (range(1, 6) as $n) {
            $f->list->tasks()->create(['assignee_id' => $f->owner->id, 'status' => 'todo', 'title' => 'Job card '.$n]);
        }

        Livewire::actingAs($f->owner)->test(Landing::class)
            ->assertSeeHtml('data-land-count>6<')
            ->assertSee('See all 6 in My tasks')
            ->assertSeeHtml('href="'.route('tasks.mine').'"');

        $page = $this->actingAs($f->owner)->get(route('dashboard'))->getContent();
        $this->assertSame(Landing::SHOWN, substr_count($page, 'data-land-card'));
    }

    public function test_a_task_written_on_the_page_seats_in_the_slot(): void
    {
        $f = $this->teamFixture();
        $landing = Livewire::actingAs($f->viewer)->test(Landing::class)->assertSee('Nothing is on your name.');

        Livewire::actingAs($f->viewer)->test(QuickAdd::class)->dispatch('open-quick-add')->set('sentence', 'Call the supplier')->call('save');

        $landing->dispatch('task-created')->assertSee('Call the supplier')->assertDontSee('Nothing is on your name.')->assertSeeHtml('data-land-count>1<');
    }

    public function test_nobody_sees_another_persons_private_inbox_on_the_landing(): void
    {
        $f = $this->teamFixture();
        Livewire::actingAs($f->editor)->test(QuickAdd::class)->dispatch('open-quick-add')->set('sentence', 'Private errand')->call('save');

        Livewire::actingAs($f->owner)->test(Landing::class)->assertDontSee('Private errand');
        Livewire::actingAs($f->outsider)->test(Landing::class)->assertDontSee('Private errand')->assertDontSee('Fixture task');
    }

    public function test_the_way_back_to_infodot_and_the_platforms_comes_from_the_ecosystem_config(): void
    {
        $user = $this->newMember();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('<a href="https://infodot.co.za/home">InfoDot</a>', false)
            ->assertSee('<a href="https://infodot.co.za/platforms">Platforms</a>', false);

        config(['ecosystem.platforms.infodot.url' => 'https://infodot.example/']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSee('<a href="https://infodot.example/home">InfoDot</a>', false)
            ->assertSee('<a href="https://infodot.example/platforms">Platforms</a>', false);
    }

    public function test_what_the_page_offered_before_is_still_reachable(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('Overview')->assertSee('Arrange')->assertSee('Mine')->assertSee('Team')->assertSee('Business')
            ->assertSee('Fixture board')->assertSee('href="'.route('task-lists.show', $f->list).'"', false)
            ->assertSee('href="'.route('task-lists.create').'"', false)->assertSee('New board');

        $this->actingAs($f->viewer)->get(route('dashboard'))->assertOk()
            ->assertDontSee('New board')->assertSee('You can read every board');
    }

    public function test_the_platform_list_holds_only_platforms_that_still_exist(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), self::PLATFORMS)));
        $this->assertSame('Dot.Tasks', config('ecosystem.platforms.tasks.name'));
    }
}
