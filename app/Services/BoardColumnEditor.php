<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A board's columns. Every board keeps at least one to-do column and one done column, because a new task and a finished
 * task each need somewhere to go. Callers authorize first.
 */
class BoardColumnEditor
{
    private const MOST = 12;

    public function add(TaskList $board, string $name, string $category): TaskStatus
    {
        $name = $this->validName($board, $name);
        $this->validCategory($category);

        if ($board->columns()->count() >= self::MOST) {
            throw ValidationException::withMessages(['column' => 'A board can have up to '.self::MOST.' columns.']);
        }

        return $board->columns()->create(['name' => $name, 'category' => $category, 'sort_order' => (int) $board->columns()->max('sort_order') + 1]);
    }

    public function rename(TaskStatus $column, string $name): void
    {
        $column->update(['name' => $this->validName($column->taskList, $name, $column)]);
    }

    /** What a column means can only change while it is empty: otherwise cards would finish or reopen without anyone moving them. */
    public function recategorize(TaskStatus $column, string $category): void
    {
        $this->validCategory($category);

        if ($this->cards($column)->exists()) {
            throw ValidationException::withMessages(['column' => 'Move the cards out of '.$column->name.' first.']);
        }

        $this->keepsWhatItNeeds($column, $category);
        $column->update(['category' => $category]);
    }

    public function place(TaskStatus $column, ?int $beforeId = null): void
    {
        DB::transaction(function () use ($column, $beforeId) {
            $order = $column->taskList->columns()->pluck('id')->reject(fn (int $id) => $id === $column->id)->values();
            $at = $beforeId === null ? false : $order->search($beforeId);
            $order->splice($at === false ? $order->count() : $at, 0, [$column->id]);

            foreach ($order as $index => $id) {
                TaskStatus::whereKey($id)->update(['sort_order' => $index + 1]);
            }
        });
    }

    /** Its cards go to the nearest column that means the same thing, or to the first to-do column. */
    public function remove(TaskStatus $column): void
    {
        $this->keepsWhatItNeeds($column, null);

        DB::transaction(function () use ($column) {
            $others = $column->taskList->columns()->whereKeyNot($column->id)->get();
            $home = $others->firstWhere('category', $column->category) ?? $others->firstWhere('category', 'todo');

            $this->cards($column)->update(['status_id' => $home->id, 'status' => $home->category]);
            $column->delete();
        });
    }

    /** Every card in the column, archived and removed ones included, so none is left pointing at nothing. */
    private function cards(TaskStatus $column)
    {
        return Task::withTrashed()->withArchived()->where('status_id', $column->id);
    }

    private function keepsWhatItNeeds(TaskStatus $column, ?string $category): void
    {
        foreach (['todo' => 'to-do', 'done' => 'done'] as $needed => $word) {
            $alone = $column->category === $needed && $category !== $needed
                && ! $column->taskList->columns()->whereKeyNot($column->id)->where('category', $needed)->exists();

            if ($alone) {
                throw ValidationException::withMessages(['column' => 'A board needs at least one '.$word.' column.']);
            }
        }
    }

    private function validCategory(string $category): void
    {
        if (! array_key_exists($category, TaskStatus::CATEGORIES)) {
            throw ValidationException::withMessages(['column' => 'Pick what the column means.']);
        }
    }

    private function validName(TaskList $board, string $name, ?TaskStatus $column = null): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 40) {
            throw ValidationException::withMessages(['column' => 'Give the column a name of up to 40 characters.']);
        }

        $taken = $board->columns()->when($column, fn ($q) => $q->whereKeyNot($column->id))->pluck('name')
            ->contains(fn (string $other) => mb_strtolower($other) === mb_strtolower($name));
        if ($taken) {
            throw ValidationException::withMessages(['column' => 'This board already has a column called '.$name.'.']);
        }

        return $name;
    }
}
