<?php

namespace App\Services;

use App\Exceptions\RestoreRefused;
use App\Models\RecordRestoration;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists a team's deleted tasks and brings them back.
 *
 * Reaching removed rows. Tasks have no team of their own (the tenant is the
 * board's team) and two global scopes hide rows from every normal query: deleted
 * tasks, and archived ones. A deleted task on an ARCHIVED board must still be
 * found (it is exactly what the team may want back once the board is restored),
 * and `Task::forTeam()` would miss it, because it goes through the board relation
 * and archived boards are scoped out. So the queries here switch the scopes off
 * and re-apply the team through the boards table (archived boards included).
 * A task id from another company simply is not found.
 *
 * Restoring. A task cannot come back while its parent task is still deleted or
 * archived, or while its board is archived. Restoring a task also brings back the
 * subtasks (any depth) deleted in the same delete: TaskArchiveService::delete()
 * stamps the whole cascade with one deleted_at, so "deleted with it" is an exact
 * match on that stamp, and a subtask deleted on its own earlier stays deleted.
 * Nothing is replayed: no notification is sent, no recurrence is spawned.
 * Dependent state is re-derived rather than copied: an assignee who has left the
 * team is cleared, and blockers that are still deleted simply do not block (the
 * relations already leave deleted tasks out), while a restored blocker blocks
 * its waiting tasks again. One append-only RecordRestoration row is written.
 */
class RemovedTaskService
{
    public const PER_PAGE = 20;

    /** @return Builder<Task> deleted tasks on any board of $team (archived boards included), scopes off */
    public function query(Team|int $team): Builder
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return Task::withoutGlobalScopes()
            ->onlyTrashed()
            ->whereIn('tasks.task_list_id', TaskList::withoutGlobalScopes()->where('team_id', $teamId)->select('task_lists.id'));
    }

    public function find(int $taskId, Team|int $team): ?Task
    {
        return $this->query($team)->whereKey($taskId)->first();
    }

    /** @return Collection<int, TaskList> every board of the team, archived ones included, for the board filter */
    public function boards(Team|int $team): Collection
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return TaskList::withoutGlobalScopes()->where('team_id', $teamId)->orderBy('name')->get();
    }

    /** One page of deleted tasks, newest deletion first. @return LengthAwarePaginator<int, RemovedTaskItem> */
    public function page(Team|int $team, string $type = '', string $board = '', string $search = '', int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $query = $this->query($team)
            ->when($type === 'task', fn (Builder $q) => $q->whereNull('tasks.parent_id'))
            ->when($type === 'subtask', fn (Builder $q) => $q->whereNotNull('tasks.parent_id'))
            ->when(ctype_digit($board), fn (Builder $q) => $q->where('tasks.task_list_id', (int) $board))
            ->when(trim($search) !== '', fn (Builder $q) => $q->searchText($search))
            ->orderByDesc('tasks.deleted_at')
            ->orderByDesc('tasks.id');

        $page = $query->paginate($perPage);

        $boards = TaskList::withoutGlobalScopes()->whereIn('id', $page->getCollection()->pluck('task_list_id')->unique())->get()->keyBy('id');
        $parents = Task::withoutGlobalScopes()->whereIn('id', $page->getCollection()->pluck('parent_id')->filter()->unique())->get()->keyBy('id');

        return $page->setCollection($page->getCollection()->map(function (Task $task) use ($boards, $parents) {
            $board = $boards[$task->task_list_id] ?? null;

            return new RemovedTaskItem($task, $board, $parents[$task->parent_id] ?? null, $this->blocker($task, $board));
        }));
    }

    /**
     * The reason this task cannot be restored yet, or null. The topmost deleted
     * ancestor is named (restoring it brings its removed subtasks back too), then
     * an archived parent, then an archived board.
     *
     * @return array{kind: string, name: string}|null
     */
    public function blocker(Task $task, ?TaskList $board): ?array
    {
        $topDeleted = null;
        $archivedParent = null;
        $current = $task;

        for ($depth = 0; $current->parent_id !== null && $depth < 50; $depth++) {
            $parent = Task::withoutGlobalScopes()->find($current->parent_id);

            if ($parent === null) {
                break;
            }

            if ($parent->trashed()) {
                $topDeleted = $parent;
                $current = $parent;

                continue;
            }

            $archivedParent = $parent->archived_at !== null ? $parent : null;

            break;
        }

        return match (true) {
            $topDeleted !== null => ['kind' => 'parent_removed', 'name' => $topDeleted->title],
            $archivedParent !== null => ['kind' => 'parent_archived', 'name' => $archivedParent->title],
            $board === null => ['kind' => 'board_missing', 'name' => ''],
            $board->archived_at !== null => ['kind' => 'board_archived', 'name' => $board->name],
            default => null,
        };
    }

    /**
     * @throws RestoreRefused when the parent task or the board is still gone
     * @throws ModelNotFoundException when the task is not (or no longer) deleted on one of this team's boards
     */
    public function restore(Task $task, Team $team, User $by): TaskRestoreResult
    {
        return DB::transaction(function () use ($task, $team, $by) {
            $locked = $this->query($team)->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $board = TaskList::withoutGlobalScopes()->find($locked->task_list_id);

            $this->refuseIfBlocked($locked, $board);

            $withIt = $this->deletedWith($locked);
            $ids = [$locked->id, ...$withIt];

            Task::withoutGlobalScopes()->onlyTrashed()->whereIn('id', $ids)->restore();

            $members = $team->allUsers()->pluck('id')->all();
            $cleared = Task::withoutGlobalScopes()->whereIn('id', $ids)
                ->whereNotNull('assignee_id')
                ->whereNotIn('assignee_id', $members)
                ->update(['assignee_id' => null]);

            $result = new TaskRestoreResult(
                title: $locked->title,
                isSubtask: $locked->parent_id !== null,
                subtasks: count($withIt),
                assigneesCleared: $cleared,
                waiting: $this->waitingOn($ids),
                stillArchived: $locked->archived_at !== null,
            );

            RecordRestoration::create([
                'team_id' => $team->id,
                'user_id' => $by->id,
                'record_type' => $result->isSubtask ? 'subtask' : 'task',
                'record_id' => $locked->id,
                'record_label' => mb_substr($locked->title, 0, 255),
                'restored_with' => $result->subtasks,
            ]);

            return $result;
        });
    }

    private function refuseIfBlocked(Task $task, ?TaskList $board): void
    {
        $blocker = $this->blocker($task, $board);

        if ($blocker === null) {
            return;
        }

        $what = $task->parent_id !== null ? 'subtask' : 'task';

        throw RestoreRefused::because(match ($blocker['kind']) {
            'parent_removed' => "The {$what} \"{$task->title}\" cannot be restored while its parent task \"{$blocker['name']}\" is removed. Restore the parent task first.",
            'parent_archived' => "The {$what} \"{$task->title}\" cannot be restored while its parent task \"{$blocker['name']}\" is archived. Restore the parent task from its board's Archived list first.",
            'board_archived' => "The {$what} \"{$task->title}\" cannot be restored while its board \"{$blocker['name']}\" is archived. Restore the board first.",
            default => "The {$what} \"{$task->title}\" cannot be restored because its board no longer exists.",
        });
    }

    /**
     * Ids of the subtasks (any depth) deleted in the same delete as $root: the same deleted_at stamp.
     *
     * @return list<int>
     */
    private function deletedWith(Task $root): array
    {
        $found = [];
        $frontier = [$root->id];

        while ($frontier !== []) {
            $children = Task::withoutGlobalScopes()->onlyTrashed()
                ->whereIn('parent_id', $frontier)
                ->where('deleted_at', $root->deleted_at)
                ->pluck('id');

            $frontier = $children->diff($found)->values()->all();
            $found = [...$found, ...$frontier];
        }

        return $found;
    }

    /**
     * How many live, unfinished tasks are blocked by any of $ids (they wait on them again now).
     *
     * @param  list<int>  $ids
     */
    private function waitingOn(array $ids): int
    {
        return DB::table('task_dependencies')
            ->join('tasks', 'tasks.id', '=', 'task_dependencies.task_id')
            ->whereIn('task_dependencies.blocked_by_id', $ids)
            ->whereNull('tasks.deleted_at')
            ->where('tasks.status', '!=', 'done')
            ->distinct()
            ->count('tasks.id');
    }
}
