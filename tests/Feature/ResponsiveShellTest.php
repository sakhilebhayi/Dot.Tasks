<?php

namespace Tests\Feature;

use App\Livewire\Tasks\TaskBoard;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/**
 * The responsive shell: a drawer opened by a labelled Menu button below 860px,
 * the markup hooks the docs name, and the kanban board's touch "Move to..."
 * control. Real authenticated GETs of the real routes; no pixel assertions.
 */
class ResponsiveShellTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    /** @return array<string, string> page name => url, for the pages that live inside the app layout */
    private function shellPages(object $f): array
    {
        return [
            'dashboard' => route('dashboard'),
            'my tasks' => route('tasks.mine'),
            'today' => route('tasks.today'),
            'upcoming' => route('tasks.upcoming'),
            'labels' => route('labels.index'),
            'new list' => route('task-lists.create'),
            'board' => route('task-lists.show', $f->list),
            'notifications' => route('notifications.index'),
            'escalations' => route('escalations.index'),
            'profile' => route('profile.show'),
            'team settings' => route('teams.show', $f->team->id),
        ];
    }

    private function drawerHtml(TestResponse $response): string
    {
        $this->assertSame(1, preg_match('/<aside id="shell-drawer".*?<\/aside>/s', $response->getContent(), $m), 'The layout has no #shell-drawer element.');

        return $m[0];
    }

    private function selectHtml(string $html, int $taskId): string
    {
        preg_match('/<select id="move-'.$taskId.'".*?<\/select>/s', $html, $m);

        return $m[0] ?? '';
    }

    public function test_every_page_in_the_layout_has_the_menu_button_drawer_backdrop_and_viewport_meta(): void
    {
        $f = $this->teamFixture();

        foreach ($this->shellPages($f) as $name => $url) {
            $response = $this->actingAs($f->owner)->get($url)->assertOk();
            $html = $response->getContent();

            // Viewport meta, or phones render a 980px canvas.
            $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1"', $html, "{$name}: viewport meta");

            // A labelled Menu button that controls the drawer and starts collapsed.
            $this->assertSame(1, preg_match('/<button[^>]*id="shell-menu-button"[^>]*>(.*?)<\/button>/s', $html, $button), "{$name}: menu button");
            $this->assertStringContainsString('aria-controls="shell-drawer"', $button[0], "{$name}: aria-controls");
            $this->assertStringContainsString('aria-expanded="false"', $button[0], "{$name}: aria-expanded");
            $this->assertStringContainsString('Menu', strip_tags($button[1]), "{$name}: visible label");

            // The drawer is the existing sidebar, labelled, and the backdrop closes it.
            $this->assertStringContainsString('class="sidebar"', $this->drawerHtml($response), "{$name}: drawer is the sidebar");
            $this->assertStringContainsString('id="shell-backdrop"', $html, "{$name}: backdrop");
            $this->assertStringContainsString('class="topbar"', $html, "{$name}: top bar");
        }
    }

    public function test_the_drawer_holds_every_nav_link_the_team_switcher_and_a_log_out_form(): void
    {
        $f = $this->teamFixture();
        Team::forceCreate(['user_id' => $f->owner->id, 'name' => 'Second Shell Team', 'personal_team' => false]);

        // fresh(): the fixture's owner already has its teams relation loaded.
        $drawer = $this->drawerHtml($this->actingAs($f->owner->fresh())->get(route('dashboard'))->assertOk());

        foreach (['dashboard', 'tasks.mine', 'tasks.today', 'tasks.upcoming', 'labels.index', 'profile.show'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $drawer, "drawer link to {$route}");
        }

        // Team settings plus a way to switch to the other team, wired to Jetstream's own route.
        $this->assertStringContainsString('href="'.route('teams.show', $f->team->id).'"', $drawer);
        $this->assertStringContainsString('action="'.route('current-team.update').'"', $drawer);
        $this->assertStringContainsString('Switch to Second Shell Team', $drawer);
        $this->assertSame(1, substr_count($drawer, 'aria-current="true"'), 'exactly one team is marked current');
        $this->assertStringContainsString($f->team->name.' (current)', html_entity_decode($drawer, ENT_QUOTES));

        // Log out is a real POST form: the stock Jetstream bar that held it sat unreachable under the top bar.
        $this->assertSame(1, preg_match('/<form method="POST" action="'.preg_quote(route('logout'), '/').'">.*?Log out/s', $drawer));
    }

    public function test_a_user_with_one_team_sees_no_switch_to_rows(): void
    {
        $f = $this->teamFixture();

        $drawer = $this->drawerHtml($this->actingAs($f->editor)->get(route('dashboard'))->assertOk());

        $this->assertStringNotContainsString('Switch to ', $drawer);
        $this->assertStringContainsString('Team settings', $drawer);
    }

    public function test_the_hidden_stock_jetstream_navigation_bar_is_gone_so_content_offset_equals_the_top_bar(): void
    {
        $f = $this->teamFixture();

        $response = $this->actingAs($f->owner)->get(route('dashboard'))->assertOk();

        $response->assertDontSeeLivewire('navigation-menu');
        $response->assertDontSee('Manage Account');
        // One source of truth for the bar height; the content padding uses the same variable.
        $response->assertSee('--topbar-h: 54px', false);
        $response->assertSee('.content-wrap { padding-top:var(--topbar-h); }', false);
    }

    public function test_the_breakpoint_reduced_motion_and_scroll_container_rules_are_in_the_layout(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->owner)->get(route('dashboard'))->assertOk()
            ->assertSee('@media (max-width:859.98px)', false)
            ->assertSee("matchMedia('(min-width: 860px)')", false)
            ->assertSee('@media (prefers-reduced-motion: reduce)', false)
            ->assertSee('.table-scroll {', false);
    }

    public function test_alpine_is_not_loaded_a_second_time_from_a_cdn(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->owner)->get(route('dashboard'))->assertOk()
            ->assertDontSee('cdn.min.js', false)
            ->assertDontSee('unpkg.com/alpinejs', false);
    }

    public function test_the_dashboard_uses_the_stacking_grid_and_page_padding_classes(): void
    {
        $f = $this->teamFixture();

        $html = $this->actingAs($f->owner)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<div class="page-pad">/', $html));
        $this->assertSame(2, preg_match_all('/<div class="kpi-grid" /', $html), 'the two KPI rows');
        $this->assertSame(1, preg_match_all('/<div class="kpi-grid list-grid">/', $html), 'the lists grid');
        $this->assertSame(7, preg_match_all('/<div class="kpi-tile">/', $html), 'seven KPI tiles');
        $this->assertStringContainsString('class="list-card"', $html);
        // No fixed three-column inline style left that a phone could not override.
        $this->assertStringNotContainsString('grid-template-columns:repeat(3,1fr)', $html);
    }

    public function test_the_board_columns_scroll_inside_their_own_labelled_region(): void
    {
        $f = $this->teamFixture();

        $html = $this->actingAs($f->editor)->get(route('task-lists.show', $f->list))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<div class="kanban-scroll [^"]*"[^>]*role="region"[^>]*aria-label="Task board columns"/', $html));
        $this->assertSame(4, preg_match_all('/<div class="kanban-col /', $html), 'one column per status');
    }

    public function test_an_editor_gets_a_visible_move_to_select_on_every_card_without_the_current_status(): void
    {
        $f = $this->teamFixture();
        $other = $f->list->tasks()->create(['title' => 'Second card for the move test', 'status' => 'review']);

        $html = $this->actingAs($f->editor)->get(route('task-lists.show', $f->list))->assertOk()->getContent();

        foreach ([$f->task, $other] as $task) {
            $select = $this->selectHtml($html, $task->id);

            $this->assertStringContainsString('wire:change="moveTask('.$task->id.', $event.target.value)"', $select, "select for task {$task->id}");
            $this->assertStringContainsString('Move to...', $select);
            // Always rendered, never inside a hover-only wrapper.
            $this->assertStringNotContainsString('group-hover', $select);
            $this->assertStringNotContainsString('hidden', $select);
            $this->assertStringContainsString('<label for="move-'.$task->id.'" class="sr-only">Move', $html);
        }

        $todoCard = $this->selectHtml($html, $f->task->id);
        $this->assertStringNotContainsString('value="todo"', $todoCard, 'a todo card is not offered "To Do"');
        $this->assertStringContainsString('value="done"', $todoCard);
        $this->assertStringNotContainsString('value="review"', $this->selectHtml($html, $other->id));
    }

    public function test_a_viewer_gets_no_move_control(): void
    {
        $f = $this->teamFixture();

        $html = $this->actingAs($f->viewer)->get(route('task-lists.show', $f->list))->assertOk()->getContent();

        $this->assertStringContainsString('Fixture task', $html);
        $this->assertStringNotContainsString('id="move-'.$f->task->id.'"', $html);
        $this->assertStringNotContainsString('wire:change="moveTask', $html);
    }

    public function test_choosing_a_column_in_the_select_moves_the_card_and_every_attempt_rekeys_the_selects(): void
    {
        $f = $this->teamFixture();

        $board = Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list]);
        $this->assertSame(0, $board->get('moveAttempts'));

        $board->call('moveTask', $f->task->id, 'review')->assertHasNoErrors();

        $this->assertSame('review', $f->task->fresh()->status);
        $this->assertSame(1, $board->get('moveAttempts'));
    }

    public function test_a_refused_move_leaves_the_card_and_still_resets_the_select(): void
    {
        $f = $this->teamFixture();
        $blocker = $f->list->tasks()->create(['title' => 'The unfinished blocker', 'status' => 'todo']);
        DB::table('task_dependencies')->insert(['task_id' => $f->task->id, 'blocked_by_id' => $blocker->id]);

        $board = Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])
            ->call('moveTask', $f->task->id, 'done')
            ->assertHasErrors(['status']);

        $this->assertSame('todo', $f->task->fresh()->status);
        $this->assertSame(1, $board->get('moveAttempts'), 'the counter that re-keys the selects moves even when the move is refused');
    }
}
