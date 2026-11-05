<?php

namespace Tests\Browser;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** The list view the way a person works in it. */
class TaskTableTest extends DuskTestCase
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

    private function open(Browser $browser, User $user, TaskList $list, string $query = 'view=list'): Browser
    {
        return $browser->loginAs($user)->openLivewire("/lists/{$list->id}?{$query}");
    }

    private function assertNoConsoleErrors(Browser $browser): void
    {
        $errors = array_filter(
            $browser->driver->manage()->getLog('browser'),
            fn (array $entry) => $entry['level'] === 'SEVERE' && ! str_contains($entry['message'], 'fonts.g') && ! str_contains($entry['message'], 'favicon.ico'),
        );

        $this->assertSame([], array_values($errors));
    }

    public function test_a_row_is_edited_where_it_sits(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Inspect the haul road');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);
            $browser->script("const t = document.querySelector('.ghost-input'); t.value = 'Grade the haul road'; t.dispatchEvent(new Event('change', { bubbles: true }));");
            $browser->settle();
            $browser->select('[aria-label="Priority of Grade the haul road"]', 'urgent')->settle();

            $this->assertSame('Grade the haul road', $task->fresh()->title);
            $this->assertSame('urgent', $task->fresh()->priority);
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_a_row_dragged_by_its_handle_moves_to_another_status(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Service the crusher');

        $this->browse(function (Browser $browser) use ($user, $list, $task) {
            $this->open($browser, $user, $list);

            $handle = $browser->driver->findElement(WebDriverBy::cssSelector('.trow .grip'));
            $target = $browser->driver->findElements(WebDriverBy::cssSelector('.tgroup'))[2];
            $browser->driver->action()->moveToElement($handle)->clickAndHold()->moveByOffset(4, 8)
                ->moveToElement($target)->moveByOffset(0, 2)->moveByOffset(0, 1)->release()->perform();
            $browser->settle();

            $this->assertSame('review', $task->fresh()->status);
            $browser->refresh()->waitFor('.trow');
            $this->assertSame(1, (int) $browser->driver->executeScript('return document.querySelectorAll(\'.tgroup[data-category="review"] [data-task]\').length'));
            $this->assertNoConsoleErrors($browser);
        });
    }

    public function test_two_rows_are_selected_and_changed_together(): void
    {
        [$user, $list] = $this->board();
        $first = $this->task($list, 'First');
        $second = $this->task($list, 'Second');

        $this->browse(function (Browser $browser) use ($user, $list, $first, $second) {
            $this->open($browser, $user, $list);
            $browser->check('[aria-label="Select First"]')->settle()->check('[aria-label="Select Second"]')->settle()
                ->waitFor('.bulk')->assertSeeIn('.bulk', '2')
                ->select('[aria-label="Set the priority of what is selected"]', 'high')->settle()
                ->waitForText('2 tasks changed.')->assertMissing('.bulk');

            $this->assertSame('high', $first->fresh()->priority);
            $this->assertSame('high', $second->fresh()->priority);
        });
    }

    public function test_subtasks_open_under_their_row(): void
    {
        [$user, $list] = $this->board();
        $task = $this->task($list, 'Plan the shutdown');
        $this->task($list, 'Book the crane', ['parent_id' => $task->id]);

        $this->browse(function (Browser $browser) use ($user, $list) {
            $this->open($browser, $user, $list);
            $browser->assertDontSee('Book the crane')
                ->click('.expand')->waitForText('Book the crane');
        });
    }

    public function test_a_saved_view_comes_back_from_its_link(): void
    {
        [$user, $list] = $this->board();
        $this->task($list, 'Rotate the shifts');

        $this->browse(function (Browser $browser) use ($user, $list) {
            $this->open($browser, $user, $list, 'view=list&group=priority');
            $browser->press('Save this view')->waitFor('[aria-label="Name this view"]')
                ->type('[aria-label="Name this view"]', 'By priority')->keys('[aria-label="Name this view"]', '{enter}')
                ->waitForText('By priority');

            $this->open($browser, $user, $list);
            $browser->clickLink('By priority')->waitFor('.trow');
            $this->assertStringContainsString('group=priority', $browser->driver->getCurrentURL());
            $this->assertStringContainsString('view=list', $browser->driver->getCurrentURL());
        });
    }
}
