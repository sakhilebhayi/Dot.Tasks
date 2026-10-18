<?php

namespace Tests\Feature;

use App\Livewire\Tasks\MyTasks;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class MyTasksTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function mine(object $f, string $as = 'editor', array $query = [])
    {
        return Livewire::actingAs($f->{$as})->withQueryParams($query)->test(MyTasks::class);
    }

    private function task(object $f, string $title, array $attrs = [], ?TaskList $list = null): Task
    {
        return ($list ?? $f->list)->tasks()->create($attrs + ['title' => $title, 'status' => 'todo', 'assignee_id' => $f->editor->id]);
    }

    public function test_the_page_renders_for_every_role_under_my_tasks(): void
    {
        $f = $this->teamFixture();

        foreach (['owner', 'admin', 'editor', 'viewer'] as $role) {
            $this->actingAs($f->{$role})->get(route('tasks.mine'))->assertOk()->assertSee('My tasks');
        }
    }

    public function test_it_lists_only_tasks_assigned_to_me_across_all_boards_of_the_team(): void
    {
        $f = $this->teamFixture();
        $second = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Second board']);

        $this->task($f, 'Mine on the first board');
        $this->task($f, 'Mine on the second board', [], $second);
        $this->task($f, 'Assigned to somebody else', ['assignee_id' => $f->admin->id]);
        $this->task($f, 'Assigned to nobody', ['assignee_id' => null]);

        $this->mine($f)
            ->assertSee('Mine on the first board')
            ->assertSee('Mine on the second board')
            ->assertSee('Second board')
            ->assertDontSee('Assigned to somebody else')
            ->assertDontSee('Assigned to nobody');
    }

    public function test_tasks_from_a_different_team_i_belong_to_are_not_shown(): void
    {
        $f = $this->teamFixture();
        $g = $this->teamFixture();
        $g->team->users()->attach($f->editor, ['role' => 'editor']);
        $this->task($g, 'Assigned to me but on the other team');
        $this->task($f, 'Mine here');

        $this->mine($f)
            ->assertSee('Mine here')
            ->assertDontSee('Assigned to me but on the other team');
    }

    public function test_done_tasks_are_hidden_unless_asked_for(): void
    {
        $f = $this->teamFixture();
        $this->task($f, 'Already finished work', ['status' => 'done']);
        $this->task($f, 'Still open work');

        $this->mine($f)->assertSee('Still open work')->assertDontSee('Already finished work');
        $this->mine($f, 'editor', ['done' => '1'])->assertSee('Already finished work')->assertSee('Still open work');
    }

    public function test_ordered_by_priority_rank_then_due_date_with_undated_last(): void
    {
        $f = $this->teamFixture();
        $this->task($f, 'Zulu low priority sooner', ['priority' => 'low', 'due_date' => now()->addDay()->toDateString()]);
        $this->task($f, 'Echo urgent later', ['priority' => 'urgent', 'due_date' => now()->addDays(9)->toDateString()]);
        $this->task($f, 'Alpha urgent sooner', ['priority' => 'urgent', 'due_date' => now()->addDays(2)->toDateString()]);
        $this->task($f, 'Bravo urgent undated', ['priority' => 'urgent']);
        $this->task($f, 'Mike high', ['priority' => 'high']);
        $this->task($f, 'November medium', ['priority' => 'medium']);

        $this->mine($f)->assertSeeInOrder([
            'Alpha urgent sooner',
            'Echo urgent later',
            'Bravo urgent undated',
            'Mike high',
            'November medium',
            'Zulu low priority sooner',
        ]);
    }

    public function test_it_filters_by_priority_label_due_state_and_text(): void
    {
        $f = $this->teamFixture();
        $label = Label::create(['team_id' => $f->team->id, 'name' => 'Finance work', 'color' => '#14b8a6']);
        $this->task($f, 'High and labelled and overdue', ['priority' => 'high', 'due_date' => now()->subDay()->toDateString()])->labels()->attach($label->id);
        $this->task($f, 'Low and plain', ['priority' => 'low']);

        $this->mine($f)->set('priority', 'high')->assertSee('High and labelled')->assertDontSee('Low and plain');
        $this->mine($f)->set('labelId', (string) $label->id)->assertSee('High and labelled')->assertDontSee('Low and plain');
        $this->mine($f)->set('due', 'overdue')->assertSee('High and labelled')->assertDontSee('Low and plain');
        $this->mine($f)->set('due', 'none')->assertSee('Low and plain')->assertDontSee('High and labelled');
        $this->mine($f)->set('search', 'plain')->assertSee('Low and plain')->assertDontSee('High and labelled');
    }

    public function test_filters_are_url_bound(): void
    {
        foreach (['search', 'priority', 'labelId', 'due', 'showDone'] as $property) {
            $this->assertNotEmpty((new ReflectionProperty(MyTasks::class, $property))->getAttributes(Url::class), $property);
        }

        $f = $this->teamFixture();
        $this->task($f, 'Bookmarked high task', ['priority' => 'high']);
        $this->task($f, 'Bookmarked low task', ['priority' => 'low']);

        $this->actingAs($f->editor)->get(route('tasks.mine').'?priority=high')
            ->assertOk()
            ->assertSee('Bookmarked high task')
            ->assertDontSee('Bookmarked low task');
    }

    public function test_archived_and_deleted_tasks_and_archived_boards_are_hidden(): void
    {
        $f = $this->teamFixture();
        $this->task($f, 'Archived assignment')->archive();
        $this->task($f, 'Deleted assignment')->delete();
        $gone = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Gone board']);
        $this->task($f, 'Assignment on an archived board', [], $gone);
        $gone->archive();
        $this->task($f, 'Live assignment');

        $this->mine($f)
            ->assertSee('Live assignment')
            ->assertDontSee('Archived assignment')
            ->assertDontSee('Deleted assignment')
            ->assertDontSee('Assignment on an archived board');
    }

    public function test_an_editor_can_complete_a_task_from_the_list_and_a_viewer_cannot(): void
    {
        $f = $this->teamFixture();
        $task = $this->task($f, 'Complete me from the list', ['assignee_id' => $f->viewer->id]);

        Livewire::actingAs($f->viewer)->test(MyTasks::class)->call('completeTask', $task->id)->assertForbidden();
        $this->assertSame('todo', $task->fresh()->status);

        $task->update(['assignee_id' => $f->editor->id]);
        $this->mine($f)->call('completeTask', $task->id)->assertDontSee('Complete me from the list');
        $this->assertSame('done', $task->fresh()->status);
    }

    public function test_completing_a_recurring_task_spawns_the_next_one(): void
    {
        $f = $this->teamFixture();
        $task = $this->task($f, 'Weekly review', ['recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'due_date' => now()->toDateString()]);

        $this->mine($f)->call('completeTask', $task->id);

        $this->assertSame(1, Task::where('recurrence_parent_id', $task->id)->count());
    }

    public function test_it_tells_me_when_there_is_nothing_assigned(): void
    {
        $f = $this->teamFixture();

        $this->mine($f)->assertSee('Nothing is assigned to you');
    }
}
