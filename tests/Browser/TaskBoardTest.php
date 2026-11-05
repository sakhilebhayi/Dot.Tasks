<?php

namespace Tests\Browser;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use App\Models\User;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
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
        return $browser->driver->findElement(WebDriverBy::cssSelector("[data-title=\"{$title}\"]"));
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

    /** A real pointer drag: press on the card, travel, let go over the target. */
    private function dragTo(Browser $browser, string $title, RemoteWebElement $target, int $down = 2): void
    {
        $browser->driver->action()
            ->moveToElement($this->card($browser, $title))->clickAndHold()->moveByOffset(8, 8)
            ->moveToElement($target)->moveByOffset(0, $down)->moveByOffset(0, 1)
            ->release()->perform();
        $browser->settle();
    }

    private function column(Browser $browser, int $index): RemoteWebElement
    {
        return $browser->driver->findElements(WebDriverBy::cssSelector('.slot-body'))[$index];
    }

    /** @return list<string> the titles on screen, top to bottom */
    private function titles(Browser $browser, int $index): array
    {
        return $browser->driver->executeScript("return [...document.querySelectorAll('.slot-body')[{$index}].querySelectorAll('[data-task]')].map((card) => card.dataset.title)");
    }

    public function test_a_card_dragged_to_another_column_stays_there_after_a_refresh(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Inspect the haul road');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $this->dragTo($browser, 'Inspect the haul road', $this->column($browser, 1));

            $this->assertSame('in_progress', $task->fresh()->status);
            $this->assertSame([], $this->titles($browser, 0));
            $this->assertSame(['Inspect the haul road'], $this->titles($browser, 1));

            $browser->refresh()->waitForText('Inspect the haul road');
            $browser->assertSeeIn('.kanban-col:nth-child(2)', 'Inspect the haul road');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_card_is_put_in_order_inside_its_column_and_stays_there(): void
    {
        [$user, $list] = $this->board();
        foreach (['First', 'Second', 'Third'] as $order => $title) {
            $this->task($list, $title, ['sort_order' => $order + 1]);
        }

        $this->browse(function (Browser $browser) use ($user, $list) {
            $this->open($browser, $user, $list);
            $this->dragTo($browser, 'Third', $this->card($browser, 'First'), -10);

            $this->assertSame(['Third', 'First', 'Second'], $this->titles($browser, 0));

            $browser->refresh()->waitForText('Third');
            $this->assertSame(['Third', 'First', 'Second'], $this->titles($browser, 0));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_drop_the_server_refuses_puts_the_card_back_and_says_why(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Service the crusher');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $task->update(['status' => 'review']);
            $this->dragTo($browser, 'Service the crusher', $this->column($browser, 1));

            $browser->assertSee('Someone else moved that card, so here is where it is now.');
            $this->assertSame([], $this->titles($browser, 0));
            $this->assertSame([], $this->titles($browser, 1));
            $this->assertSame(['Service the crusher'], $this->titles($browser, 2));
            $this->assertSame('review', $task->fresh()->status);
        });
    }

    public function test_a_lost_connection_puts_the_card_back_and_says_so(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Order conveyor spares');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $browser->driver->executeScript("window.fetch = () => Promise.reject(new TypeError('Failed to fetch'))");
            $this->dragTo($browser, 'Order conveyor spares', $this->column($browser, 2));

            $browser->waitForText('That did not save, so the card is back where it was.');
            $this->assertSame(['Order conveyor spares'], $this->titles($browser, 0));
            $this->assertSame([], $this->titles($browser, 2));
            $this->assertSame('todo', $task->fresh()->status);
        });
    }

    public function test_a_blocked_card_will_not_seat_in_done(): void
    {
        [$user, $list] = $this->board();
        $blocker = $this->task($list, 'Sign off the permit');
        $task = $this->task($list, 'Start the shutdown');
        $task->blockedBy()->attach($blocker->id);

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $this->dragTo($browser, 'Start the shutdown', $this->column($browser, 3));

            $this->assertSame([], $this->titles($browser, 3));
            $this->assertContains('Start the shutdown', $this->titles($browser, 0));
            $browser->assertSeeIn('#announce', 'A blocked task cannot be finished yet.');
            $this->assertSame('todo', $task->fresh()->status);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_the_keyboard_moves_a_card_and_stays_on_it(): void
    {
        [$user, $list] = $this->board();
        $first = $this->task($list, 'First', ['sort_order' => 1]);
        $second = $this->task($list, 'Second', ['sort_order' => 2]);

        $this->browse(function (Browser $browser) use ($user, $list, $first) {
            $this->open($browser, $user, $list);

            $press = function (string $key) use ($browser) {
                $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::ALT, $key]);
                $browser->driver->getKeyboard()->releaseKey(WebDriverKeys::ALT);
                $browser->settle();
            };
            $focused = fn () => $browser->driver->executeScript('return document.activeElement.dataset.title');

            $browser->driver->executeScript("document.querySelector('[data-task=\"{$first->id}\"]').focus()");
            $press(WebDriverKeys::ARROW_DOWN);
            $this->assertSame(['Second', 'First'], $this->titles($browser, 0));
            $this->assertSame('First', $focused());

            $press(WebDriverKeys::ARROW_UP);
            $this->assertSame(['First', 'Second'], $this->titles($browser, 0));

            $press(WebDriverKeys::ARROW_RIGHT);
            $this->assertSame('in_progress', $first->fresh()->status);
            $this->assertSame('First', $focused());
            $browser->assertSeeIn('#announce', 'First moved to In Progress.');
        });
    }

    public function test_lanes_let_a_card_change_hands_by_dragging(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Rotate the shifts', ['assignee_id' => $user->id]);
        $list->team->users()->attach(User::factory()->create(['name' => 'Naledi Khumalo']), ['role' => 'editor']);

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $browser->select('[aria-label="Group into lanes"]', 'assignee')->settle()->waitForText('Naledi Khumalo');

            $lane = $browser->driver->findElement(WebDriverBy::xpath("//details[contains(., 'Naledi Khumalo')]//div[@data-category='todo']"));
            $browser->driver->executeScript('arguments[0].scrollIntoView({ block: "center" })', [$lane]);
            $this->dragTo($browser, 'Rotate the shifts', $lane);

            $this->assertNotSame($user->id, $task->fresh()->assignee_id);
            $this->assertNotNull($task->fresh()->assignee_id);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_subtasks_move_with_the_keyboard_from_their_handle(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Plan the shutdown');
        foreach (['Book', 'Brief'] as $order => $title) {
            $this->task($list, $title, ['parent_id' => $task->id, 'sort_order' => $order + 1]);
        }

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $this->card($browser, 'Plan the shutdown')->click();
            $browser->settle()->waitFor('.step .grip');

            $browser->driver->executeScript("document.querySelector('.step .grip').focus()");
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ARROW_DOWN);
            $browser->settle();

            $this->assertSame(['Brief', 'Book'], $task->subtasks()->pluck('title')->all());
            $this->assertSame(1, (int) $browser->driver->executeScript("return document.activeElement.classList.contains('grip') ? 1 : 0"));
        });
    }

    public function test_a_board_gets_a_new_column_and_a_card_moves_into_it(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Chase the supplier');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $browser->press('Columns')->waitFor('[aria-label="New column"]')
                ->type('[aria-label="New column"]', 'Waiting')->press('Add')->waitForText('Waiting')
                ->press('Done')->settle();

            $browser->waitFor('[data-name="Waiting"]');
            $this->dragTo($browser, 'Chase the supplier', $browser->driver->findElement(WebDriverBy::cssSelector('[data-name="Waiting"]')));

            $this->assertSame('Waiting', TaskStatus::find($task->fresh()->status_id)->name);
            $this->assertNoConsoleErrors($browser);
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

            $browser->type('textarea[wire\\:model="commentBody"]', 'Broker has the quote')->press('Comment')->settle();

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
            $place = fn (string $status) => $browser->script("Livewire.find(document.querySelector('.slot-body').closest('[wire\\\\:id]').getAttribute('wire:id')).place({$task->id}, '{$status}')");

            $this->dragTo($browser, 'Weekly inspection', $this->column($browser, 3));
            $place('todo');
            $browser->settle();
            $place('done');
            $browser->settle();

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
