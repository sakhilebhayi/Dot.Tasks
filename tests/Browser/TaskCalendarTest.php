<?php

namespace Tests\Browser;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Moving work through time by hand, in a real browser. */
class TaskCalendarTest extends DuskTestCase
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

    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = array_filter(
            $browser->driver->manage()->getLog('browser'),
            fn (array $entry) => $entry['level'] === 'SEVERE' && ! str_contains($entry['message'], 'fonts.g') && ! str_contains($entry['message'], 'favicon.ico'),
        );

        $this->assertSame([], array_values($errors));
    }

    public function test_a_card_dragged_to_another_day_stays_there_after_a_refresh(): void
    {
        [$user, $list] = $this->board();
        $today = Carbon::today();
        $task = $this->task($list, 'Pay the rates', ['due_date' => $today->toDateString()]);
        $target = $today->copy()->day($today->day === 15 ? 16 : 15)->toDateString();

        $this->browse(function (Browser $browser) use ($user, $list, $task, $target) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=calendar");
            $card = $browser->driver->findElement(WebDriverBy::cssSelector("[data-task=\"{$task->id}\"]"));
            $day = $browser->driver->findElement(WebDriverBy::cssSelector(".cal-list[data-date=\"{$target}\"]"));

            $browser->driver->action()->moveToElement($card)->clickAndHold()->moveByOffset(6, 6)
                ->moveToElement($day)->moveByOffset(0, 3)->moveByOffset(0, 1)->release()->perform();
            $browser->settle();

            $this->assertSame($target, $task->fresh()->due_date->toDateString());
            $browser->refresh()->waitFor('.cal-card')
                ->assertPresent(".cal-list[data-date=\"{$target}\"] [data-task=\"{$task->id}\"]");
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_card_with_no_date_is_dragged_onto_one(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Order spares');
        $target = Carbon::today()->toDateString();

        $this->browse(function (Browser $browser) use ($user, $list, $task, $target) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=calendar");
            $card = $browser->driver->findElement(WebDriverBy::cssSelector("[data-task=\"{$task->id}\"]"));
            $day = $browser->driver->findElement(WebDriverBy::cssSelector(".cal-list[data-date=\"{$target}\"]"));

            $browser->driver->action()->moveToElement($card)->clickAndHold()->moveByOffset(-6, 6)
                ->moveToElement($day)->moveByOffset(0, 3)->moveByOffset(0, 1)->release()->perform();
            $browser->settle();

            $this->assertSame($target, $task->fresh()->due_date->toDateString());
        });
    }

    public function test_the_keyboard_changes_a_date_and_stays_on_the_card(): void
    {
        [$user, $list] = $this->board();
        $due = Carbon::today()->startOfMonth()->addDays(10);
        $task = $this->task($list, 'Pay the rates', ['due_date' => $due->toDateString()]);

        $this->browse(function (Browser $browser) use ($user, $list, $task, $due) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=calendar&date={$due->toDateString()}");
            $browser->driver->executeScript("document.querySelector('[data-task=\"{$task->id}\"]').focus()");
            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::ALT, WebDriverKeys::ARROW_RIGHT]);
            $browser->driver->getKeyboard()->releaseKey(WebDriverKeys::ALT);
            $browser->settle();

            $this->assertSame($due->copy()->addDay()->toDateString(), $task->fresh()->due_date->toDateString());
            $this->assertSame($task->title, $browser->driver->executeScript('return document.activeElement.dataset.title'));
        });
    }

    public function test_a_task_is_added_on_a_day(): void
    {
        [$user, $list] = $this->board();
        $today = Carbon::today()->toDateString();

        $this->browse(function (Browser $browser) use ($user, $list, $today) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=calendar");
            $browser->script("document.querySelector('.cal-day.today .cal-add').click()");
            $browser->waitFor('.cal-day.today form input')
                ->keys('.cal-day.today form input', 'Book the auditors', '{enter}')
                ->waitFor('.cal-card');

            $this->assertSame($today, Task::where('title', 'Book the auditors')->sole()->due_date->toDateString());
        });
    }

    private function dragBar(Browser $browser, string $selector, int $days, int $from = 0): void
    {
        $bar = $browser->driver->findElement(WebDriverBy::cssSelector($selector));
        $browser->driver->action()->moveToElement($bar, $from)->clickAndHold()->moveByOffset(8, 0)->moveByOffset(26 * $days - 8, 0)->release()->perform();
        $browser->settle();
    }

    public function test_a_bar_is_moved_and_stretched_by_hand(): void
    {
        [$user, $list] = $this->board();
        $start = Carbon::today()->addDays(2);
        $due = $start->copy()->addDays(4);
        $task = $this->task($list, 'Reline the crusher', ['start_date' => $start->toDateString(), 'due_date' => $due->toDateString()]);

        $this->browse(function (Browser $browser) use ($user, $list, $task, $start, $due) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=timeline");

            $this->dragBar($browser, '[data-bar]', 3);
            $this->assertSame($start->copy()->addDays(3)->toDateString(), $task->fresh()->start_date->toDateString());
            $this->assertSame($due->copy()->addDays(3)->toDateString(), $task->fresh()->due_date->toDateString());

            $this->dragBar($browser, '[data-edge="due"]', 2);
            $this->assertSame($start->copy()->addDays(3)->toDateString(), $task->fresh()->start_date->toDateString());
            $this->assertSame($due->copy()->addDays(5)->toDateString(), $task->fresh()->due_date->toDateString());

            $this->dragBar($browser, '[data-edge="start"]', 1);
            $this->assertSame($start->copy()->addDays(4)->toDateString(), $task->fresh()->start_date->toDateString());

            $browser->refresh()->waitFor('[data-bar]');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_press_without_a_drag_opens_the_task(): void
    {
        [$user, $list] = $this->board();
        $this->task($list, 'Reline the crusher', ['due_date' => Carbon::today()->addDays(3)->toDateString(), 'start_date' => Carbon::today()->toDateString()]);

        $this->browse(function (Browser $browser) use ($user, $list) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=timeline");
            $browser->driver->findElement(WebDriverBy::cssSelector('[data-bar]'))->click();
            $browser->waitFor('#task-title')->assertValue('#task-title', 'Reline the crusher');
        });
    }

    public function test_the_keyboard_moves_and_stretches_a_bar(): void
    {
        [$user, $list] = $this->board();
        $start = Carbon::today()->addDays(2);
        $task = $this->task($list, 'Reline the crusher', ['start_date' => $start->toDateString(), 'due_date' => $start->copy()->addDays(2)->toDateString()]);

        $this->browse(function (Browser $browser) use ($user, $list, $task, $start) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=timeline");
            $browser->driver->executeScript("document.querySelector('[data-bar]').focus()");

            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::ALT, WebDriverKeys::ARROW_RIGHT]);
            $browser->driver->getKeyboard()->releaseKey(WebDriverKeys::ALT);
            $browser->settle();
            $this->assertSame($start->copy()->addDay()->toDateString(), $task->fresh()->start_date->toDateString());

            $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::ALT, WebDriverKeys::SHIFT, WebDriverKeys::ARROW_RIGHT]);
            $browser->driver->getKeyboard()->releaseKey(WebDriverKeys::ALT);
            $browser->driver->getKeyboard()->releaseKey(WebDriverKeys::SHIFT);
            $browser->settle();
            $this->assertSame($start->copy()->addDay()->toDateString(), $task->fresh()->start_date->toDateString());
            $this->assertSame($start->copy()->addDays(4)->toDateString(), $task->fresh()->due_date->toDateString());
        });
    }

    public function test_a_lost_connection_puts_a_bar_back(): void
    {
        [$user, $list] = $this->board();
        $start = Carbon::today()->addDays(2);
        $task = $this->task($list, 'Reline the crusher', ['start_date' => $start->toDateString(), 'due_date' => $start->copy()->addDays(4)->toDateString()]);

        $this->browse(function (Browser $browser) use ($user, $list, $task, $start) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}?view=timeline");
            $left = fn () => $browser->driver->executeScript("return document.querySelector('[data-bar]').style.left");
            $before = $left();
            $browser->driver->executeScript("window.fetch = () => Promise.reject(new TypeError('Failed to fetch'))");

            $this->dragBar($browser, '[data-bar]', 3);

            $browser->waitForText('That did not save');
            $this->assertSame($before, $left());
            $this->assertSame($start->toDateString(), $task->fresh()->start_date->toDateString());
        });
    }
}
