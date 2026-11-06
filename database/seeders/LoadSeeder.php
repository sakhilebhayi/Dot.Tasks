<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Synthetic volume for the local load test, never real data: one business ("Load test business") with 20 people and
 * 8 boards. The first board holds 2,000 tasks and the others 2,600 each, 20,200 in all, with a task in five as a
 * subtask, and a label and a comment on every tenth. Every name says it is a load-test record. It refuses to run in
 * production.
 *
 *   php artisan db:seed --class=LoadSeeder
 */
class LoadSeeder extends Seeder
{
    public const BOARDS = 8;

    public const FIRST_BOARD = 2000;

    public const OTHER_BOARDS = 2600;

    public const PEOPLE = 20;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LoadSeeder writes synthetic records and never runs in production.');
        }

        $now = Carbon::now();
        $owner = User::query()->firstWhere('email', 'test@example.com') ?? User::factory()->withPersonalTeam()->create(['email' => 'test@example.com', 'name' => 'Load owner']);
        $team = Team::query()->forceCreate(['user_id' => $owner->id, 'name' => 'Load test business', 'personal_team' => false]);
        $team->users()->syncWithoutDetaching([$owner->id => ['role' => 'admin']]);
        $owner->forceFill(['current_team_id' => $team->id])->save();

        $people = collect(range(1, self::PEOPLE))->map(fn ($i) => User::factory()->create(['name' => 'Load person '.$i, 'current_team_id' => $team->id]));
        $people->each(fn ($person) => $team->users()->attach($person, ['role' => 'editor']));
        $ids = $people->pluck('id')->all();

        $label = DB::table('labels')->insertGetId(['team_id' => $team->id, 'name' => 'Load label', 'color' => '#7c7e76', 'created_at' => $now, 'updated_at' => $now]);

        foreach (range(1, self::BOARDS) as $n) {
            $board = $team->taskLists()->create(['owner_id' => $owner->id, 'name' => 'Load board '.$n]);
            $columns = $board->columns()->get()->keyBy('category');
            $count = $n === 1 ? self::FIRST_BOARD : self::OTHER_BOARDS;

            foreach (array_chunk(range(1, $count), 500) as $chunk) {
                DB::table('tasks')->insert(array_map(function (int $i) use ($board, $columns, $ids, $now) {
                    $status = match (true) {
                        $i % 5 < 3 => 'done',
                        $i % 5 === 3 => 'in_progress',
                        default => 'todo',
                    };

                    return [
                        'task_list_id' => $board->id, 'title' => "Load task {$board->id}-{$i}", 'status' => $status, 'status_id' => $columns[$status]->id,
                        'priority' => ['low', 'medium', 'high'][$i % 3], 'assignee_id' => $ids[$i % count($ids)], 'sort_order' => $i,
                        'due_date' => $now->copy()->addDays(($i % 90) - 30)->toDateString(), 'completed_at' => $status === 'done' ? $now->copy()->subDays($i % 60) : null,
                        'created_at' => $now->copy()->subDays($i % 180), 'updated_at' => $now,
                    ];
                }, $chunk));
            }

            $tasks = DB::table('tasks')->where('task_list_id', $board->id)->orderBy('id')->pluck('id');
            foreach ($tasks->filter(fn ($id, $i) => $i % 10 === 0)->chunk(500) as $chunk) {
                DB::table('task_label')->insert($chunk->map(fn ($id) => ['task_id' => $id, 'label_id' => $label])->values()->all());
                DB::table('task_comments')->insert($chunk->map(fn ($id) => ['task_id' => $id, 'user_id' => $ids[$id % count($ids)], 'body' => 'Load comment', 'created_at' => $now, 'updated_at' => $now])->values()->all());
            }
            foreach ($tasks->filter(fn ($id, $i) => $i % 5 === 1)->chunk(500) as $chunk) {
                DB::table('tasks')->whereIn('id', $chunk->all())->update(['parent_id' => $tasks->first()]);
            }
        }
    }
}
