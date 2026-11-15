<?php

namespace Tests\Feature;

use App\Livewire\Tasks\FindTasks;
use App\Livewire\Tasks\HeaderSearch;
use App\Livewire\Tasks\MyTasks;
use App\Livewire\Tasks\RemovedTasks;
use App\Livewire\Tasks\TaskBoard;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R-52 tenant-OR sweep: every text search combines `title LIKE ... OR description LIKE ...` and sits beside the team scope.
 * The OR must be grouped (Task::scopeSearchText wraps it in a closure) so a match on the DESCRIPTION of another team's task
 * cannot slip past the team scope. These tests plant exactly that row.
 */
class SearchTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $mine = User::factory()->withPersonalTeam()->create();
        $theirs = User::factory()->withPersonalTeam()->create();
        $myBoard = TaskList::create(['team_id' => $mine->currentTeam->id, 'owner_id' => $mine->id, 'name' => 'Mine']);
        $theirBoard = TaskList::create(['team_id' => $theirs->currentTeam->id, 'owner_id' => $theirs->id, 'name' => 'Theirs']);
        Task::create(['task_list_id' => $myBoard->id, 'creator_id' => $mine->id, 'assignee_id' => $mine->id, 'title' => 'Own quarterly report', 'description' => 'zebra', 'status' => 'todo']);
        // Title does not match, DESCRIPTION does: the row an ungrouped orWhere would leak.
        $leak = Task::create(['task_list_id' => $theirBoard->id, 'creator_id' => $theirs->id, 'assignee_id' => $theirs->id, 'title' => 'Unrelated title', 'description' => 'zebra secret plan', 'status' => 'todo']);

        return [$mine, $theirs, $myBoard, $theirBoard, $leak];
    }

    public function test_the_header_search_and_the_find_palette_never_match_another_teams_description(): void
    {
        [$mine] = $this->world();

        Livewire::actingAs($mine)->test(HeaderSearch::class)->set('query', 'zebra')->assertSee('Own quarterly report')->assertDontSee('Unrelated title');
        Livewire::actingAs($mine)->test(FindTasks::class)->dispatch('open-search')->set('query', 'zebra')->assertSee('Own quarterly report')->assertDontSee('Unrelated title');
    }

    public function test_my_tasks_and_the_board_filter_never_match_another_teams_description(): void
    {
        [$mine, , $myBoard] = $this->world();

        Livewire::actingAs($mine)->test(MyTasks::class)->set('search', 'zebra')->assertSee('Own quarterly report')->assertDontSee('Unrelated title');
        Livewire::actingAs($mine)->test(TaskBoard::class, ['taskList' => $myBoard])->set('search', 'zebra')->assertSee('Own quarterly report')->assertDontSee('Unrelated title');
    }

    public function test_the_removed_tasks_search_never_matches_another_teams_description(): void
    {
        [$mine, , $myBoard, , $leak] = $this->world();
        $own = Task::create(['task_list_id' => $myBoard->id, 'creator_id' => $mine->id, 'title' => 'Deleted own', 'description' => 'zebra', 'status' => 'todo']);
        $own->delete();
        $leak->delete();

        Livewire::actingAs($mine)->test(RemovedTasks::class)->set('search', 'zebra')->assertSee('Deleted own')->assertDontSee('Unrelated title');
    }
}
