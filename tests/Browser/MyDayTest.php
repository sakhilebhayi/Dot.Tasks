<?php

namespace Tests\Browser;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Planning a day and sharing out work by hand, and the same by touch. */
class MyDayTest extends DuskTestCase
{
    use DatabaseTruncation;

    /** @return array{0: User, 1: TaskList} */
    private function board(): array
    {
        $user = User::factory()->withPersonalTeam()->create();
        $list = TaskList::create(['team_id' => $user->currentTeam->id, 'owner_id' => $user->id, 'name' => 'Monthly operations']);

        return [$user, $list];
    }

    private function task(TaskList $list, User $user, string $title, array $attributes = []): Task
    {
        return Task::create($attributes + ['task_list_id' => $list->id, 'title' => $title, 'status' => 'todo', 'priority' => 'medium', 'assignee_id' => $user->id]);
    }

    private function drag(Browser $browser, string $from, string $to): void
    {
        $browser->driver->action()
            ->moveToElement($browser->driver->findElement(WebDriverBy::cssSelector($from)))->clickAndHold()->moveByOffset(6, 6)
            ->moveToElement($browser->driver->findElement(WebDriverBy::cssSelector($to)))->moveByOffset(0, 3)->moveByOffset(0, 1)
            ->release()->perform();
        $browser->settle();
    }

    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = array_filter(
            $browser->driver->manage()->getLog('browser'),
            fn (array $entry) => $entry['level'] === 'SEVERE' && ! str_contains($entry['message'], 'fonts.g') && ! str_contains($entry['message'], 'favicon.ico'),
        );

        $this->assertSame([], array_values($errors));
    }

    public function test_work_is_dragged_into_the_plan_and_put_in_order(): void
    {
        [$user, $list] = $this->board();
        $today = Carbon::today()->toDateString();
        $first = $this->task($list, $user, 'First thing', ['due_date' => $today]);
        $second = $this->task($list, $user, 'Second thing', ['due_date' => $today]);

        $this->browse(function (Browser $browser) use ($user, $first, $second) {
            $browser->loginAs($user)->openLivewire('/today');

            $this->drag($browser, ".source [data-task=\"{$first->id}\"] .day-title", '.plan');
            $browser->waitFor("[data-plan] [data-task=\"{$first->id}\"]");
            $this->drag($browser, ".source [data-task=\"{$second->id}\"] .day-title", "[data-plan] [data-task=\"{$first->id}\"]");
            $browser->waitFor("[data-plan] [data-task=\"{$second->id}\"]");

            $browser->refresh()->waitFor('[data-plan] [data-task]');
            $this->assertSame(2, count($browser->driver->findElements(WebDriverBy::cssSelector('[data-plan] [data-task]'))));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_card_dropped_on_a_day_of_the_rack_moves_there(): void
    {
        [$user, $list] = $this->board();
        $today = Carbon::today();
        $task = $this->task($list, $user, 'Move me', ['due_date' => $today->toDateString()]);
        $sunday = $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();

        $this->browse(function (Browser $browser) use ($user, $task, $sunday) {
            $browser->loginAs($user)->openLivewire('/today');

            $this->drag($browser, ".source [data-task=\"{$task->id}\"] .day-title", ".week-drop[data-date=\"{$sunday}\"]");

            $this->assertSame($sunday, $task->fresh()->due_date->toDateString());
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_card_handed_over_on_the_team_page_changes_hands(): void
    {
        [$user, $list] = $this->board();
        $other = User::factory()->create(['name' => 'Naledi Khumalo']);
        $list->team->users()->attach($other, ['role' => 'editor']);
        $task = $this->task($list, $user, 'Rotate the shifts');

        $this->browse(function (Browser $browser) use ($user, $task, $other) {
            $browser->loginAs($user)->openLivewire('/team-work');

            $this->drag($browser, "[data-task=\"{$task->id}\"]", ".slot-body[data-person=\"{$other->id}\"]");

            $this->assertSame($other->id, $task->fresh()->assignee_id);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_card_dropped_on_a_board_in_the_sidebar_moves_there(): void
    {
        [$user, $list] = $this->board();
        $other = TaskList::create(['team_id' => $list->team_id, 'owner_id' => $user->id, 'name' => 'Safety']);
        $task = $this->task($list, $user, 'Move me across');

        $this->browse(function (Browser $browser) use ($user, $list, $other, $task) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}");

            $this->drag($browser, "[data-task=\"{$task->id}\"]", "[data-board=\"{$other->id}\"]");

            $this->assertSame($other->id, $task->fresh()->task_list_id);
            $browser->assertMissing("[data-task=\"{$task->id}\"]")->assertSeeIn('#announce', 'moved to Safety');
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_card_is_dragged_by_touch_after_a_short_hold(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, $user, 'By finger');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $browser->loginAs($user)->openLivewire("/lists/{$list->id}");

            $point = fn (string $selector) => $browser->driver->executeScript('const r = document.querySelector(arguments[0]).getBoundingClientRect(); return { x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) }', [$selector]);
            $from = $point("[data-task=\"{$task->id}\"]");
            $to = $point('.slot-body[data-category="review"]');

            $tools = new ChromeDevToolsDriver($browser->driver);
            $touch = fn (string $type, array $points) => $tools->execute('Input.dispatchTouchEvent', ['type' => $type, 'touchPoints' => $points]);

            $touch('touchStart', [$from]);
            usleep(300000);
            $touch('touchMove', [['x' => $from['x'] + 12, 'y' => $from['y'] + 12]]);
            usleep(60000);
            $touch('touchMove', [$to]);
            usleep(60000);
            $touch('touchMove', [['x' => $to['x'], 'y' => $to['y'] + 2]]);
            usleep(120000);
            $touch('touchEnd', []);
            $browser->settle();

            $this->assertSame('review', $task->fresh()->status);
        });
    }
}
