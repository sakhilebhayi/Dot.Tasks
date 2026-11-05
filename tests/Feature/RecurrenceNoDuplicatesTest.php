<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Services\TaskRecurrenceService;
use App\Services\TaskStatusService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecurrenceNoDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private TaskList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->withPersonalTeam()->create();
        $this->list = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Weekly']);
    }

    private function recurring(array $attributes = []): Task
    {
        return Task::create($attributes + [
            'task_list_id' => $this->list->id, 'title' => 'Inspect the road', 'status' => 'todo', 'priority' => 'medium',
            'due_date' => '2026-11-02', 'recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date',
        ]);
    }

    private function move(Task $task, string $status): void
    {
        app(TaskStatusService::class)->move($task->fresh(), $status);
    }

    public function test_finishing_makes_one_next_occurrence_from_the_task_that_finished(): void
    {
        $task = $this->recurring();

        $this->move($task, 'done');

        $next = Task::where('id', '!=', $task->id)->sole();
        $this->assertSame($task->id, $next->recurrence_source_id);
        $this->assertSame($task->id, $next->recurrence_parent_id);
        $this->assertSame('2026-11-09', $next->due_date->toDateString());
    }

    public function test_dropping_a_done_task_on_done_again_makes_no_second_occurrence(): void
    {
        $task = $this->recurring();

        $this->move($task, 'done');
        $this->move($task, 'done');

        $this->assertSame(2, Task::count());
    }

    public function test_reopening_and_finishing_again_makes_no_second_occurrence(): void
    {
        $task = $this->recurring();

        $this->move($task, 'done');
        $this->move($task, 'todo');
        $this->move($task, 'done');

        $this->assertSame(2, Task::count());
    }

    public function test_the_series_still_runs_on_from_each_occurrence(): void
    {
        $task = $this->recurring();

        $this->move($task, 'done');
        $second = Task::where('recurrence_source_id', $task->id)->sole();
        $this->move($second, 'done');
        $third = Task::where('recurrence_source_id', $second->id)->sole();

        $this->assertSame($task->id, $third->recurrence_parent_id);
        $this->assertSame('2026-11-16', $third->due_date->toDateString());
        $this->assertSame(3, Task::count());
    }

    public function test_an_occurrence_someone_deleted_is_not_made_again(): void
    {
        $task = $this->recurring();
        $this->move($task, 'done');
        Task::where('recurrence_source_id', $task->id)->sole()->delete();

        $this->move($task, 'todo');
        $this->move($task, 'done');

        $this->assertSame(1, Task::count());
        $this->assertSame(2, Task::withTrashed()->count());
    }

    public function test_an_occurrence_someone_archived_is_not_made_again(): void
    {
        $task = $this->recurring();
        $this->move($task, 'done');
        Task::where('recurrence_source_id', $task->id)->sole()->archive();

        $this->move($task, 'todo');
        $this->move($task, 'done');

        $this->assertSame(2, Task::withArchived()->count());
    }

    public function test_the_database_itself_refuses_a_second_occurrence(): void
    {
        $task = $this->recurring();
        $this->move($task, 'done');

        // What two people finishing at the same moment comes down to: both pass the check, one insert loses.
        $service = new class extends TaskRecurrenceService
        {
            protected function alreadyMade(Task $task): bool
            {
                return false;
            }
        };

        $this->assertNull($service->spawnNextOccurrence($task->fresh()));
        $this->assertSame(2, Task::count());
    }

    /** Postgres abandons a transaction after a refused insert, so the normal path must never reach the database's refusal. */
    public function test_a_second_finish_does_not_even_try_to_insert(): void
    {
        foreach (['stays', 'delete', 'archive'] as $fate) {
            $task = $this->recurring();
            $this->move($task, 'done');
            $next = Task::where('recurrence_source_id', $task->id)->sole();
            if ($fate !== 'stays') {
                $next->{$fate}();
            }

            $service = new class extends TaskRecurrenceService
            {
                public int $tries = 0;

                protected function makeOccurrence(Task $completedTask, Carbon $nextDueDate, Task $series): Task
                {
                    $this->tries++;

                    return parent::makeOccurrence($completedTask, $nextDueDate, $series);
                }
            };
            $this->app->instance(TaskRecurrenceService::class, $service);

            $this->move($task, 'todo');
            $this->move($task, 'done');
            $this->app->forgetInstance(TaskRecurrenceService::class);

            $this->assertSame(0, $service->tries, "The next occurrence {$fate}.");
        }
    }

    public function test_a_task_with_no_recurrence_makes_nothing(): void
    {
        $task = Task::create(['task_list_id' => $this->list->id, 'title' => 'Once', 'status' => 'todo', 'priority' => 'low']);

        $this->move($task, 'done');

        $this->assertSame(1, Task::count());
    }

    public function test_a_failed_occurrence_leaves_the_task_as_it_was(): void
    {
        $task = $this->recurring();
        DB::statement("CREATE TRIGGER stop_occurrence BEFORE INSERT ON tasks WHEN NEW.recurrence_parent_id IS NOT NULL BEGIN SELECT RAISE(ABORT, 'stopped'); END");

        try {
            $this->move($task, 'done');
            $this->fail('The insert should have failed.');
        } catch (QueryException) {
        }

        $this->assertSame('todo', $task->fresh()->status);
    }

    public function test_existing_series_are_linked_oldest_first(): void
    {
        $root = $this->recurring();
        $first = $this->recurring(['recurrence_parent_id' => $root->id]);
        $second = $this->recurring(['recurrence_parent_id' => $root->id]);
        $this->assertNull($second->fresh()->recurrence_source_id);

        $migration = require database_path('migrations/2026_11_05_000001_add_recurrence_source_to_tasks.php');
        $migration->down();
        $migration->up();

        $this->assertSame($root->id, $first->fresh()->recurrence_source_id);
        $this->assertSame($first->id, $second->fresh()->recurrence_source_id);
    }
}
