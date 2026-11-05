<?php

namespace App\Services;

use App\Models\DayPlan;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A person's ordered plan for a day. It is theirs alone and never changes the task. Callers authorize first. */
class DayPlanner
{
    /** @return Collection<int, int> task ids, in order */
    public function ids(User $user, Carbon $day): Collection
    {
        return $this->of($user, $day)->orderBy('position')->orderBy('id')->pluck('task_id');
    }

    /** On the plan, before another task or at the foot. */
    public function put(User $user, Task $task, Carbon $day, ?int $beforeId = null): void
    {
        DB::transaction(function () use ($user, $task, $day, $beforeId) {
            $order = $this->ids($user, $day)->reject(fn (int $id) => $id === $task->id)->values();
            $at = $beforeId === null ? false : $order->search($beforeId);
            $order->splice($at === false ? $order->count() : $at, 0, [$task->id]);

            $rows = $this->of($user, $day)->get()->keyBy('task_id');
            foreach ($order as $index => $id) {
                $rows->has($id)
                    ? $rows[$id]->update(['position' => $index + 1])
                    : DayPlan::create(['user_id' => $user->id, 'task_id' => $id, 'day' => $day, 'position' => $index + 1]);
            }
        });
    }

    public function remove(User $user, Task $task, Carbon $day): void
    {
        $this->of($user, $day)->where('task_id', $task->id)->delete();
    }

    /** The last day planned before this one, for work left over from it. */
    public function lastDayBefore(User $user, Carbon $day): ?Carbon
    {
        $last = DayPlan::where('user_id', $user->id)->whereDate('day', '<', $day)->max('day');

        return $last === null ? null : Carbon::parse($last)->startOfDay();
    }

    private function of(User $user, Carbon $day)
    {
        return DayPlan::where('user_id', $user->id)->whereDate('day', $day);
    }
}
