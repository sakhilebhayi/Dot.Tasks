<?php

namespace Tests\Browser;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** The board in a real browser: dragging only happens in one, and each test also fails on anything the page logs as an error. */
class TaskBoardTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** @return array{0: User, 1: TaskList} */
    private function board(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $list = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Monthly operations']);

        return [$user, $list];
    }

    private function task(TaskList $list, string $title, array $attributes = []): Task
    {
        return Task::create($attributes + ['task_list_id' => $list->id, 'title' => $title, 'status' => 'todo', 'priority' => 'medium']);
    }

    private function open(Browser $browser, User $user, TaskList $list): Browser
    {
        return $browser->loginAs($user)->openLivewire("/lists/{$list->id}");
    }

    private function card(Browser $browser, string $title)
    {
        return $browser->driver->findElement(WebDriverBy::xpath("//div[@draggable='true'][.//p[contains(., '{$title}')]]"));
    }

    private function assertNoConsoleErrors(Browser $browser): void
    {
        // Web fonts are refused on purpose in DuskTestCase.
        $errors = array_filter(
            $browser->driver->manage()->getLog('browser'),
            fn (array $entry) => $entry['level'] === 'SEVERE' && ! str_contains($entry['message'], 'fonts.g') && ! str_contains($entry['message'], 'favicon.ico'),
        );

        $this->assertSame([], array_values($errors));
    }

    public function test_a_card_dragged_to_another_column_stays_there_after_a_refresh(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Inspect the haul road');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            // WebDriver cannot perform a native browser drag, so the same events are sent by hand.
            $browser->driver->executeScript(<<<'JS'
                const card = document.querySelector('[draggable="true"]');
                const column = document.querySelectorAll('.kanban-col')[1];
                const data = new DataTransfer();
                card.dispatchEvent(new DragEvent('dragstart', { dataTransfer: data, bubbles: true }));
                column.dispatchEvent(new DragEvent('dragover', { dataTransfer: data, bubbles: true, cancelable: true }));
                column.dispatchEvent(new DragEvent('drop', { dataTransfer: data, bubbles: true, cancelable: true }));
            JS);
            $browser->settle();

            $this->assertSame('in_progress', $task->fresh()->status);
            $browser->refresh()->waitForText('Inspect the haul road');
            $browser->assertSeeIn('.kanban-col:nth-child(2)', 'Inspect the haul road');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_move_list_does_the_same_job_where_dragging_is_not_possible(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Order conveyor spares');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $browser->select("#move-{$task->id}", 'review')->settle();

            $this->assertSame('review', $task->fresh()->status);
            $browser->assertSeeIn('.kanban-col:nth-child(3)', 'Order conveyor spares');
        });
    }

    public function test_a_blocked_task_is_refused_and_stays_where_it_was(): void
    {
        [$user, $list] = $this->board();
        $blocker = $this->task($list, 'Sign off the permit');
        $task = $this->task($list, 'Start the shutdown');
        $task->blockedBy()->attach($blocker->id);

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $browser->select("#move-{$task->id}", 'done')->settle();

            $browser->assertSee('Blocked by "Sign off the permit"');
            $this->assertSame('todo', $task->fresh()->status);
        });
    }

    public function test_a_task_opens_in_the_drawer_and_takes_a_comment(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Renew the fleet insurance');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $this->card($browser, 'Renew the fleet insurance')->click();
            $browser->settle()->waitForText('Comments');

            $browser->type('textarea', 'Broker has the quote')->press('Comment')->settle();

            $browser->assertSee('Broker has the quote');
            $this->assertSame('Broker has the quote', $task->comments()->value('body'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_finishing_a_recurring_task_twice_on_the_board_makes_one_next_occurrence(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Weekly inspection', ['due_date' => '2026-11-02', 'recurrence_type' => 'weekly', 'recurrence_interval' => 1, 'recurrence_anchor' => 'due_date']);

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $browser->select("#move-{$task->id}", 'done')->settle();
            $browser->select("#move-{$task->id}", 'todo')->settle();
            $browser->select("#move-{$task->id}", 'done')->settle();

            $this->assertSame(1, Task::where('recurrence_source_id', $task->id)->count());
            $this->assertSame(2, Task::count());
        });
    }

    public function test_the_signed_in_pages_draw_from_the_built_stylesheet(): void
    {
        [$user, $list] = $this->board();
        $this->task($list, 'Update the stock count');

        $this->browse(function (Browser $browser) use ($user, $list) {
            $this->open($browser, $user, $list);

            $display = $browser->driver->executeScript("return getComputedStyle(document.querySelector('.kanban-scroll')).display");
            $this->assertSame('flex', $display, 'Tailwind classes must apply with no CDN.');
            $this->assertNoConsoleErrors($browser);
        });
    }
}
