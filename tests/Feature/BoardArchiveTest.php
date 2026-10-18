<?php

namespace Tests\Feature;

use App\Console\Commands\CheckTasksDueSoon;
use App\Console\Commands\DetectEscalationCandidates;
use App\Livewire\Tasks\ArchivedBoards;
use App\Livewire\Tasks\EscalationQueue;
use App\Livewire\Tasks\SmartViews;
use App\Livewire\Tasks\TaskBoard;
use App\Models\TaskEscalationProposal;
use App\Models\TaskList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

class BoardArchiveTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    public function test_an_admin_archives_a_board_and_lands_on_the_dashboard(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)
            ->test(TaskBoard::class, ['taskList' => $f->list])
            ->call('archiveBoard')
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull(TaskList::withArchived()->find($f->list->id)->archived_at);
        $this->assertDatabaseHas('tasks', ['id' => $f->task->id]);
    }

    public function test_the_owner_can_archive_a_board_too(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->owner)->test(TaskBoard::class, ['taskList' => $f->list])->call('archiveBoard');

        $this->assertNotNull(TaskList::withArchived()->find($f->list->id)->archived_at);
    }

    public function test_an_editor_and_a_viewer_cannot_archive_a_board(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])->call('archiveBoard')->assertForbidden();
        Livewire::actingAs($f->viewer)->test(TaskBoard::class, ['taskList' => $f->list])->call('archiveBoard')->assertForbidden();

        $this->assertNull($f->list->fresh()->archived_at);
    }

    public function test_only_admins_are_offered_the_archive_button(): void
    {
        $f = $this->teamFixture();

        Livewire::actingAs($f->admin)->test(TaskBoard::class, ['taskList' => $f->list])->assertSee('Archive board');
        Livewire::actingAs($f->editor)->test(TaskBoard::class, ['taskList' => $f->list])->assertDontSee('Archive board');
    }

    public function test_an_archived_board_is_off_the_dashboard_and_its_page_is_gone(): void
    {
        $f = $this->teamFixture();
        $f->list->archive();

        $this->actingAs($f->editor)->get(route('dashboard'))->assertOk()->assertDontSee('Fixture board');
        $this->actingAs($f->editor)->get(route('task-lists.show', $f->list->id))->assertNotFound();
    }

    public function test_an_archived_boards_tasks_leave_every_team_wide_view(): void
    {
        $f = $this->teamFixture();
        $f->task->update([
            'title' => 'Task on a board that gets archived',
            'due_date' => now()->subDays(20)->toDateString(),
            'assignee_id' => $f->editor->id,
        ]);
        TaskEscalationProposal::create(['task_id' => $f->task->id, 'status' => 'pending', 'reason' => 'Overdue by 20 days.']);
        $f->list->archive();

        Livewire::actingAs($f->editor)->test(SmartViews::class, ['view' => 'today'])
            ->assertDontSee('Task on a board that gets archived');
        Livewire::actingAs($f->owner)->test(EscalationQueue::class)
            ->assertDontSee('Task on a board that gets archived');

        $this->artisan(DetectEscalationCandidates::class)->assertSuccessful();
        $this->artisan(CheckTasksDueSoon::class)->assertSuccessful();

        $this->assertSame(1, TaskEscalationProposal::count(), 'no new proposal for the archived board');
        $this->assertSame(0, $f->editor->notifications()->count());
    }

    public function test_an_admin_sees_archived_boards_and_restores_one(): void
    {
        $f = $this->teamFixture();
        $f->list->archive();

        Livewire::actingAs($f->admin)->test(ArchivedBoards::class)
            ->assertSee('Fixture board')
            ->call('restoreBoard', $f->list->id);

        $this->assertNull($f->list->fresh()->archived_at);
        $this->actingAs($f->editor)->get(route('task-lists.show', $f->list))->assertOk();
    }

    public function test_an_editor_cannot_restore_a_board_and_is_not_shown_the_list(): void
    {
        $f = $this->teamFixture();
        $f->list->archive();

        Livewire::actingAs($f->editor)->test(ArchivedBoards::class)->assertDontSee('Fixture board');
        Livewire::actingAs($f->editor)->test(ArchivedBoards::class)->call('restoreBoard', $f->list->id)->assertForbidden();

        $this->assertNotNull(TaskList::withArchived()->find($f->list->id)->archived_at);
    }

    public function test_another_teams_archived_board_cannot_be_restored(): void
    {
        $f = $this->teamFixture();
        $g = $this->teamFixture();
        $g->list->archive();

        Livewire::actingAs($f->admin)->test(ArchivedBoards::class)
            ->assertDontSee('Fixture board')
            ->call('restoreBoard', $g->list->id)
            ->assertNotFound();

        $this->assertNotNull(TaskList::withArchived()->find($g->list->id)->archived_at);
    }

    public function test_the_dashboard_lists_archived_boards_for_admins_only(): void
    {
        $f = $this->teamFixture();
        $second = TaskList::create(['team_id' => $f->team->id, 'owner_id' => $f->owner->id, 'name' => 'Retired roadmap board']);
        $second->archive();

        $this->actingAs($f->admin)->get(route('dashboard'))->assertOk()->assertSee('Retired roadmap board');
        $this->actingAs($f->editor)->get(route('dashboard'))->assertOk()->assertDontSee('Retired roadmap board');
    }
}
