<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes tasks that have been soft-deleted since before a cutoff.
 *
 * Raw table queries on purpose: this runs from the console, across every team,
 * and must see (and be able to delete) deleted rows with no model scope or event
 * in the way.
 *
 * Why the guards exist: tasks.parent_id cascades on delete, so removing a parent
 * would silently delete live subtasks, and tasks.recurrence_parent_id is set null,
 * which would rewrite live tasks. Neither may happen, so:
 *  - a task past the cutoff is purged together with ALL its subtasks (they are
 *    deleted too, whatever their own age, and cannot outlive it);
 *  - if anything beneath it is still live (not deleted), it is kept, and so is
 *    every ancestor of that live task, until the next run finds it gone;
 *  - if a live task still points at it (or at one of its subtasks) as the head of a
 *    recurring series, it is kept too.
 * What hangs off a purged task (comments, label links, dependency links, escalation
 * proposals, AI logs) goes with it through the existing foreign keys; labels and
 * users are never touched. A live task blocked by a purged one simply loses that link.
 *
 * Idempotent. A dry run executes the same deletes in a transaction and rolls it
 * back, so its counts are exactly what a real run would delete.
 */
class RemovedTaskPurger
{
    private const CHUNK = 500;

    /** @return array{purged: array{tasks: int, comments: int}, kept: array{live_subtasks: int, series_heads: int}} */
    public function purge(CarbonInterface $cutoff, bool $dryRun = false): array
    {
        $purged = ['tasks' => 0, 'comments' => 0];
        $kept = ['live_subtasks' => 0, 'series_heads' => 0];

        DB::beginTransaction();
        try {
            $candidates = DB::table('tasks')->whereNotNull('deleted_at')->where('deleted_at', '<', $cutoff)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

            foreach ($candidates as $id) {
                if (! DB::table('tasks')->where('id', $id)->exists()) {
                    continue; // already purged with an ancestor
                }

                $subtree = $this->subtree($id);

                if (DB::table('tasks')->whereIn('id', $subtree)->whereNull('deleted_at')->exists()) {
                    $kept['live_subtasks']++;

                    continue;
                }

                if ($this->anyLiveTaskPointsAtSeriesHeadIn($subtree)) {
                    $kept['series_heads']++;

                    continue;
                }

                foreach (array_chunk($subtree, self::CHUNK) as $chunk) {
                    $purged['comments'] += DB::table('task_comments')->whereIn('task_id', $chunk)->count();
                }

                // Leaves first, so no row is ever deleted out from under a child.
                foreach (array_chunk(array_reverse($subtree), self::CHUNK) as $chunk) {
                    DB::table('tasks')->whereIn('id', $chunk)->delete();
                }
                $purged['tasks'] += count($subtree);
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['purged' => $purged, 'kept' => $kept];
    }

    /**
     * The task and every task beneath it, in breadth-first order, whatever their state.
     *
     * @return list<int>
     */
    private function subtree(int $rootId): array
    {
        $found = [$rootId];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $children = DB::table('tasks')->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->diff($found)->values()->all();
            $found = [...$found, ...$children];
            $frontier = $children;
        }

        return $found;
    }

    /** @param list<int> $ids */
    private function anyLiveTaskPointsAtSeriesHeadIn(array $ids): bool
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            if (DB::table('tasks')->whereIn('recurrence_parent_id', $chunk)->whereNull('deleted_at')->exists()) {
                return true;
            }
        }

        return false;
    }
}
