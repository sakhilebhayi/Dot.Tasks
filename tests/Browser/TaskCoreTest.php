<?php

namespace Tests\Browser;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Editing where the value sits, capture from anywhere, and find, the way a person does them. */
class TaskCoreTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** @return array{0: User, 1: TaskList, 2: Task} */
    private function board(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $list = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Monthly operations']);
        $task = Task::create(['task_list_id' => $list->id, 'title' => 'Inspect the haul road', 'status' => 'todo', 'priority' => 'medium']);

        return [$user, $list, $task];
    }

    public function test_a_task_is_edited_in_its_panel_and_keeps_every_change(): void
    {
        [$user, $list, $task] = $this->board();

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}")
                ->click('.card')->waitFor('#task-title');

            $browser->script("const t = document.querySelector('#task-title'); t.value = 'Grade the haul road'; t.dispatchEvent(new Event('change', { bubbles: true }));");
            $browser->settle()
                ->type('[aria-label="Add a subtask"]', 'Book the grader')->keys('[aria-label="Add a subtask"]', '{enter}')->waitForText('Book the grader')
                ->type('[aria-label="Add a checklist item"]', 'Check the permit')->keys('[aria-label="Add a checklist item"]', '{enter}')->waitForText('Check the permit')
                ->click('[role="checkbox"][aria-label="Check the permit"]')->settle();

            $browser->refresh()->waitFor('.card')->assertSee('Grade the haul road')->click('.card')->waitFor('#task-title')
                ->assertSee('Book the grader')
                ->assertSee('Check the permit')
                ->assertPresent('[role="checkbox"][aria-label="Check the permit"][aria-checked="true"]');

            $this->assertSame('Grade the haul road', $task->fresh()->title);
        });
    }

    public function test_pressing_q_takes_a_sentence_and_shows_what_it_understood_first(): void
    {
        [$user, $list] = $this->board();

        $this->browse(function (Browser $browser) use ($user, $list) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}");
            $browser->driver->getKeyboard()->sendKeys('q');
            $browser->waitFor('#quick-add-sentence')
                ->type('#quick-add-sentence', 'Pay the supplier tomorrow')
                ->waitFor('.understood')->assertSeeIn('.understood', 'Pay the supplier')->assertSeeIn('.understood', 'Due');

            $this->assertSame(1, Task::count());

            $browser->press('Create task')->waitForText('Added to Monthly operations.')->assertSeeIn('.rack', 'Pay the supplier');
            $this->assertSame(2, Task::count());
        });
    }

    public function test_a_card_is_added_at_the_foot_of_a_column_and_the_line_stays_open(): void
    {
        [$user, $list] = $this->board();

        $this->browse(function (Browser $browser) use ($user, $list) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}")
                ->click('.slot-foot .add-line')->waitFor('[aria-label="New task in To Do"]')
                ->keys('[aria-label="New task in To Do"]', 'First new', '{enter}')->waitForText('First new')
                ->keys('[aria-label="New task in To Do"]', 'Second new', '{enter}')->waitForText('Second new');

            $this->assertSame(3, Task::count());
        });
    }

    public function test_the_slash_key_finds_a_task_and_opens_it(): void
    {
        [$user, $list] = $this->board();

        $this->browse(function (Browser $browser) use ($user, $list) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}");
            $browser->driver->getKeyboard()->sendKeys('/');
            $browser->waitFor('#find-input')
                ->type('#find-input', 'haul')->waitFor('.dialog .row')
                ->assertSeeIn('.dialog', 'Inspect the haul road')
                ->click('.dialog .row')->waitFor('#task-title')
                ->assertValue('#task-title', 'Inspect the haul road');
        });
    }

    public function test_a_template_is_used_from_quick_add(): void
    {
        [$user, $list] = $this->board();
        TaskTemplate::create(['team_id' => $list->team_id, 'name' => 'Inspection', 'title' => 'Inspect the plant', 'subtasks' => ['Walk the line']]);

        $this->browse(function (Browser $browser) use ($user, $list) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}");
            $browser->driver->getKeyboard()->sendKeys('q');
            $browser->waitFor('#quick-add-template')->select('#quick-add-template', 'Inspection')->settle()
                ->press('Create task')->waitForText('Added to Monthly operations.');

            $this->assertSame(['Walk the line'], Task::where('title', 'Inspect the plant')->sole()->subtasks()->pluck('title')->all());
        });
    }

    public function test_typing_q_into_a_field_stays_in_the_field(): void
    {
        [$user, $list] = $this->board();

        $this->browse(function (Browser $browser) use ($user, $list) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}")
                ->type('input[type="search"]', 'q')->settle()
                ->assertMissing('#quick-add-sentence');
        });
    }
}
