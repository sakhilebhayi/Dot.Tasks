<?php

namespace Tests\Feature;

use App\Livewire\Tasks\RemovedTasks;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\Concerns\RemovesTasks;
use Tests\TestCase;

/**
 * The Removed page: a company's deleted tasks, newest deletion first, with a
 * type filter (tasks / subtasks), a board filter, search and pagination.
 */
class RemovedTasksPageTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;
    use RemovesTasks;

    private function page(object $f, string $as = 'admin')
    {
        return Livewire::actingAs($f->{$as})->test(RemovedTasks::class);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('removed.index'))->assertRedirect(route('login'));
    }

    public function test_every_member_can_open_the_page_and_see_a_removed_task(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);

        foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
            $this->actingAs($f->{$role})->get(route('removed.index'))
                ->assertOk()
                ->assertSee('Fixture task');
        }
    }

    public function test_the_empty_state_says_nothing_has_been_removed(): void
    {
        $f = $this->teamFixture();

        $this->page($f)->assertSee('Nothing has been removed');
    }

    public function test_only_deleted_tasks_are_listed_not_live_or_merely_archived_ones(): void
    {
        $f = $this->teamFixture();
        $live = $f->list->tasks()->create(['title' => 'Live keepsake task', 'status' => 'todo']);
        $archived = $f->list->tasks()->create(['title' => 'Archived keepsake task', 'status' => 'todo']);
        $archived->archive();
        $gone = $f->list->tasks()->create(['title' => 'Gone keepsake task', 'status' => 'todo']);
        $this->remove($gone);

        $this->page($f)
            ->assertSee('Gone keepsake task')
            ->assertDontSee('Live keepsake task')
            ->assertDontSee('Archived keepsake task');
        $this->assertNotNull($live->fresh());
    }

    public function test_a_deleted_task_on_an_archived_board_is_still_listed(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $f->list->archive();

        $this->page($f)->assertSee('Fixture task')->assertSee('Fixture board');
    }

    public function test_a_deleted_task_that_was_archived_first_is_listed_too(): void
    {
        $f = $this->teamFixture();
        $f->task->archive();
        $this->remove($f->task);

        $this->page($f)->assertSee('Fixture task');
    }

    public function test_the_newest_deletion_comes_first(): void
    {
        $f = $this->teamFixture();
        $old = $f->list->tasks()->create(['title' => 'Deleted long ago task', 'status' => 'todo']);
        $new = $f->list->tasks()->create(['title' => 'Deleted yesterday task', 'status' => 'todo']);
        $this->remove($old, '2026-03-01 10:00:00');
        $this->remove($new, '2026-03-09 10:00:00');

        $this->page($f)->assertSeeInOrder(['Deleted yesterday task', 'Deleted long ago task']);
    }

    public function test_the_type_filter_separates_tasks_from_subtasks(): void
    {
        $f = $this->teamFixture();
        $child = $this->subtask($f, $f->task, 'Fixture child step');
        $this->remove($f->task);

        $subtasks = $this->page($f)->set('type', 'subtask')->instance()->removedTasks();
        $tasks = $this->page($f)->set('type', 'task')->instance()->removedTasks();

        $this->assertSame([$child->id], $subtasks->getCollection()->map(fn ($i) => $i->task->id)->all());
        $this->assertSame([$f->task->id], $tasks->getCollection()->map(fn ($i) => $i->task->id)->all());
    }

    public function test_an_unknown_type_value_is_ignored_rather_than_trusted(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);

        $page = $this->page($f)->set('type', "x'; drop table tasks; --");

        $this->assertSame(1, $page->instance()->removedTasks()->total());
    }

    public function test_the_board_filter_narrows_to_one_board_and_ignores_a_foreign_one(): void
    {
        $f = $this->teamFixture();
        $other = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Second board']);
        $there = $other->tasks()->create(['title' => 'Task on the second board', 'status' => 'todo']);
        $this->remove($f->task);
        $this->remove($there);

        $one = $this->page($f)->set('board', (string) $other->id);
        $this->assertSame([$there->id], $one->instance()->removedTasks()->getCollection()->map(fn ($i) => $i->task->id)->all());

        $foreign = $this->teamFixture();
        $this->remove($foreign->task);
        $nothing = $this->page($f)->set('board', (string) $foreign->list->id);
        $this->assertSame(0, $nothing->instance()->removedTasks()->total());
    }

    public function test_search_matches_title_and_description_and_treats_percent_literally(): void
    {
        $f = $this->teamFixture();
        $a = $f->list->tasks()->create(['title' => 'Repaint the marula fence', 'status' => 'todo']);
        $b = $f->list->tasks()->create(['title' => 'Plain title', 'description' => 'Order baobab seedlings', 'status' => 'todo']);
        $c = $f->list->tasks()->create(['title' => 'Reach 100% coverage', 'status' => 'todo']);
        foreach ([$a, $b, $c] as $task) {
            $this->remove($task);
        }

        $titles = fn (string $q) => $this->page($f)->set('search', $q)->instance()->removedTasks()->getCollection()->map(fn ($i) => $i->task->title)->all();

        $this->assertSame(['Repaint the marula fence'], $titles('MARULA'));
        $this->assertSame(['Plain title'], $titles('baobab'));
        $this->assertSame(['Reach 100% coverage'], $titles('100%'));
        $this->assertSame(['Reach 100% coverage'], $titles('%'));
    }

    public function test_the_list_is_paginated(): void
    {
        $f = $this->teamFixture();
        foreach (range(1, 23) as $n) {
            $this->remove($f->list->tasks()->create(['title' => "Paginated removal {$n}", 'status' => 'todo']));
        }

        $first = $this->page($f)->instance()->removedTasks();
        $second = $this->page($f)->call('gotoPage', 2)->instance()->removedTasks();

        $this->assertSame(23, $first->total());
        $this->assertSame(20, $first->count());
        $this->assertSame(3, $second->count());
        $this->assertSame([], array_intersect(
            $first->getCollection()->map(fn ($i) => $i->task->id)->all(),
            $second->getCollection()->map(fn ($i) => $i->task->id)->all(),
        ));
    }

    public function test_owners_and_admins_see_a_restore_control_and_editors_and_viewers_do_not(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $control = 'wire:click="restore('.$f->task->id.')"';

        foreach (['owner', 'admin'] as $role) {
            $this->page($f, $role)->assertSeeHtml($control);
        }
        foreach (['editor', 'viewer'] as $role) {
            $this->page($f, $role)->assertDontSeeHtml($control)->assertSee('Fixture task');
        }
    }

    public function test_a_subtask_whose_parent_is_removed_names_the_parent_instead_of_offering_restore(): void
    {
        $f = $this->teamFixture();
        $f->task->update(['title' => 'Quarterly stock count']);
        $child = $this->subtask($f, $f->task, 'Count the pallets');
        $this->remove($f->task);

        $this->page($f)
            ->assertSee('Restore the parent task')
            ->assertSee('Quarterly stock count')
            ->assertDontSeeHtml('wire:click="restore('.$child->id.')"')
            ->assertSeeHtml('wire:click="restore('.$f->task->id.')"');
    }

    public function test_a_task_on_an_archived_board_says_to_restore_the_board_first(): void
    {
        $f = $this->teamFixture();
        $this->remove($f->task);
        $f->list->archive();

        $this->page($f)
            ->assertSee('Restore the board')
            ->assertSee('Fixture board')
            ->assertDontSeeHtml('wire:click="restore('.$f->task->id.')"');
    }

    public function test_the_sidebar_links_to_the_page(): void
    {
        $f = $this->teamFixture();

        $this->actingAs($f->owner)->get(route('dashboard'))->assertSee(route('removed.index'));
    }

    public function test_a_user_with_no_current_team_gets_a_clear_refusal(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('removed.index'))->assertForbidden();
        $this->assertSame(0, Task::onlyTrashed()->count());
    }
}
