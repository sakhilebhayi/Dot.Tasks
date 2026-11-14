<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one find-a-task query behind both the header search and the find palette (Ctrl K), so they can never disagree.
 *
 * Scope: Task::forTeam(), the team's boards plus the viewer's own Inbox, never another team's tasks and never someone else's
 * Inbox. Nothing is returned for fewer than two characters. Bound parameters throughout (the reader and searchText() build
 * the query with the query builder), and a hard limit.
 */
class TaskFinder
{
    public const MIN = 2;

    /** @return array{words: string, understood: list<string>, apply: list<callable>} */
    public function reading(string $query, ?Team $team, User $me): array
    {
        if (! $team) {
            return ['words' => '', 'understood' => [], 'apply' => []];
        }

        return app(TaskSearchReader::class)->read($query, $team, $me);
    }

    public function ready(string $query): bool
    {
        return mb_strlen(trim($query)) >= self::MIN;
    }

    /** @return Collection<int, Task> */
    public function tasks(string $query, ?Team $team, User $me, int $limit): Collection
    {
        if (! $team || ! $this->ready($query)) {
            return new Collection;
        }

        $read = $this->reading($query, $team, $me);

        return Task::forTeam($team)
            ->when($read['words'] !== '', fn ($q) => $q->searchText($read['words']))
            ->where(function ($q) use ($read) {
                foreach ($read['apply'] as $apply) {
                    $apply($q);
                }
            })
            ->with('taskList:id,name,is_inbox')
            ->orderByRaw("CASE WHEN tasks.status = 'done' THEN 1 ELSE 0 END")
            ->orderByDesc('tasks.updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Boards of this team whose name contains the words (LIKE metacharacters escaped). Boards are readable by every
     * member of the team (the sidebar lists them all), so there is no further filter; another team's boards never match.
     *
     * @return Collection<int, TaskList>
     */
    public function boards(string $query, ?Team $team, int $limit): Collection
    {
        $needle = trim($query);
        if (! $team || ! $this->ready($needle)) {
            return new Collection;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $needle).'%';

        return $team->boards()
            ->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
            ->orderBy('sort_order')->orderBy('name')
            ->limit($limit)
            ->get(['id', 'team_id', 'name', 'color']);
    }
}
