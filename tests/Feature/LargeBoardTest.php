<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Tasks\MyDay;
use App\Livewire\Tasks\TaskBoard;
use App\Livewire\Tasks\TaskTable;
use App\Livewire\Tasks\TeamWork;
use App\Models\Task;
use Database\Seeders\LoadSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesTeamFixtures;
use Tests\TestCase;

/** Acceptance test 26: a long list draws in batches, and pages ask the database the same number of questions at any size. */
class LargeBoardTest extends TestCase
{
    use CreatesTeamFixtures;
    use RefreshDatabase;

    private function fill(object $f, int $count, string $status = 'todo'): void
    {
        $column = $f->list->columnFor($status);
        foreach (range(1, $count) as $i) {
            $f->list->tasks()->create(['title' => "Row {$i}.", 'status' => $status, 'status_id' => $column->id, 'assignee_id' => $f->owner->id, 'sort_order' => $i, 'due_date' => today()]);
        }
    }

    private function cards(string $html): int
    {
        return preg_match_all('/ data-task="\d+"/', $html);
    }

    public function test_a_long_column_draws_its_first_cards_and_the_rest_on_request(): void
    {
        $f = $this->teamFixture();
        $this->fill($f, 130);

        $board = Livewire::actingAs($f->owner)->test(TaskBoard::class, ['taskList' => $f->list])->assertSee('Show more, 31 left');
        $this->assertSame(100, $this->cards($board->html()));

        $board->call('showMore')->assertDontSee('Show more');
        $this->assertSame(131, $this->cards($board->html()));
    }

    public function test_a_card_dropped_at_the_end_of_what_is_drawn_lands_there_and_not_past_the_hidden_ones(): void
    {
        $f = $this->teamFixture();
        $this->fill($f, 130);
        $tasks = Task::where('task_list_id', $f->list->id)->orderBy('sort_order')->orderBy('id')->get();
        $next = $tasks[100];

        $board = Livewire::actingAs($f->owner)->test(TaskBoard::class, ['taskList' => $f->list]);
        $this->assertStringContainsString('data-after="'.$next->id.'"', $board->html());

        $board->call('place', $tasks[0]->id, (string) $tasks[0]->status_id, $next->id);

        $order = Task::where('task_list_id', $f->list->id)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
        $this->assertSame(array_search($next->id, $order, true) - 1, array_search($tasks[0]->id, $order, true));
    }

    public function test_the_list_view_draws_in_batches_and_selects_only_what_is_drawn(): void
    {
        $f = $this->teamFixture();
        $this->fill($f, 130);

        $list = Livewire::actingAs($f->owner)->test(TaskTable::class, ['taskList' => $f->list])->assertSee('Show more, 31 left');
        $this->assertSame(100, $this->cards($list->html()));

        $this->assertCount(100, $list->call('toggleAll')->get('selected'));
    }

    public function test_the_team_page_draws_a_batch_per_person_and_still_counts_all_their_work(): void
    {
        $f = $this->teamFixture();
        $this->fill($f, 40);

        $page = Livewire::actingAs($f->owner)->test(TeamWork::class)->assertSee('Show more, 10 left')->assertSeeHtml('<span class="num">40</span> open');

        $this->assertSame(31, $this->cards($page->html()), '30 of theirs, and the fixture task nobody holds');
    }

    public function test_the_questions_asked_do_not_grow_with_the_number_of_tasks(): void
    {
        $f = $this->teamFixture();
        $pages = [
            [TaskBoard::class, ['taskList' => $f->list]],
            [TaskTable::class, ['taskList' => $f->list]],
            [TeamWork::class, []],
            [MyDay::class, []],
            [Dashboard::class, []],
        ];
        $asked = function () use ($pages, $f) {
            return array_map(function (array $page) use ($f) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                Livewire::actingAs($f->owner)->test(...$page);
                $count = count(DB::getQueryLog());
                DB::disableQueryLog();

                return $count;
            }, $pages);
        };

        $this->fill($f, 5);
        $asked(); // Settles what each page only fetches the first time.
        $small = $asked();
        $this->fill($f, 60);
        $large = $asked();

        $this->assertSame($small, $large);
    }

    public function test_the_load_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        (new LoadSeeder)->run();
    }
}
