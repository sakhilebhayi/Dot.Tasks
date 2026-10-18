<?php

namespace Tests\Feature;

use App\Livewire\Tasks\RemovedTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\Concerns\RemovesTasks;
use Tests\TestCase;

/**
 * Company B must never see, search for or restore company A's removed tasks,
 * even with A's ids smuggled into the restore call or the filters.
 */
class RemovedTasksCrossTeamTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;
    use RemovesTasks;

    /** @return array{0: object, 1: object} company A with removed work, company B with some of its own */
    private function twoCompanies(): array
    {
        $a = $this->teamFixture();
        $a->task->update(['title' => 'ALPHA-SECRET-TASK']);
        $a->list->update(['name' => 'ALPHA-SECRET-BOARD']);
        $child = $this->subtask($a, $a->task, 'ALPHA-SECRET-SUBTASK');
        $a->child = $child;
        $this->remove($a->task);

        $b = $this->teamFixture();
        $b->task->update(['title' => 'Bravo own removed task']);
        $this->remove($b->task);

        return [$a, $b];
    }

    public function test_a_smuggled_task_id_404s_and_changes_nothing(): void
    {
        [$a, $b] = $this->twoCompanies();
        $before = $this->fingerprint();

        foreach (['owner', 'admin'] as $role) {
            foreach ([$a->task, $a->child] as $foreign) {
                Livewire::actingAs($b->{$role})->test(RemovedTasks::class)->call('restore', $foreign->id)->assertNotFound();
            }
        }

        $this->assertSame($before, $this->fingerprint());
        $this->assertTrue($this->isRemoved($a->task));
        $this->assertTrue($this->isRemoved($a->child));
    }

    public function test_company_b_never_sees_company_as_removed_tasks_in_the_list_or_search(): void
    {
        [$a, $b] = $this->twoCompanies();

        $page = Livewire::actingAs($b->admin)->test(RemovedTasks::class);
        $page->assertSee('Bravo own removed task')
            ->assertDontSee('ALPHA-SECRET-TASK')->assertDontSee('ALPHA-SECRET-SUBTASK')->assertDontSee('ALPHA-SECRET-BOARD');
        $this->assertSame(1, $page->instance()->removedTasks()->total());

        $searched = Livewire::actingAs($b->admin)->test(RemovedTasks::class)->set('search', 'ALPHA-SECRET');
        $this->assertSame(0, $searched->instance()->removedTasks()->total());
        $searched->assertDontSee('ALPHA-SECRET-TASK');
    }

    public function test_company_as_board_id_in_the_filter_reveals_nothing_to_company_b(): void
    {
        [$a, $b] = $this->twoCompanies();

        $page = Livewire::actingAs($b->admin)->test(RemovedTasks::class)->set('board', (string) $a->list->id);

        $this->assertSame(0, $page->instance()->removedTasks()->total());
        $page->assertDontSee('ALPHA-SECRET-BOARD');
    }

    public function test_the_removed_list_of_a_team_with_an_archived_board_still_stays_inside_the_team(): void
    {
        [$a, $b] = $this->twoCompanies();
        $a->list->archive();
        $b->list->archive();

        $ids = fn (object $f) => Livewire::actingAs($f->admin)->test(RemovedTasks::class)->instance()->removedTasks()->getCollection()->map(fn ($i) => $i->task->id)->sort()->values()->all();

        $this->assertSame([$a->task->id, $a->child->id], $ids($a));
        $this->assertSame([$b->task->id], $ids($b));
    }

    public function test_a_viewer_of_company_a_cannot_restore_either(): void
    {
        [$a] = $this->twoCompanies();
        $before = $this->fingerprint();

        Livewire::actingAs($a->viewer)->test(RemovedTasks::class)->call('restore', $a->task->id)->assertForbidden();

        $this->assertSame($before, $this->fingerprint());
    }
}
