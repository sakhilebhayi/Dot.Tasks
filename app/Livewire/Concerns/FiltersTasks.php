<?php

namespace App\Livewire\Concerns;

use App\Models\Label;
use App\Models\Task;
use App\Models\TaskField;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * The task filters shared by the board and My tasks. Every one is a
 * URL-bound Livewire property, so a filtered view can be bookmarked or
 * shared, and every value read from the URL is validated here: anything that
 * is not a real priority, due state or one of this team's labels is ignored
 * rather than trusted.
 */
trait FiltersTasks
{
    public const DUE_STATES = ['overdue' => 'Overdue', 'today' => 'Due today', 'none' => 'No due date'];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(as: 'label', except: '')]
    public string $labelId = '';

    #[Url(except: '')]
    public string $due = '';

    /** A custom field of this team, and the value to look for in it. */
    #[Url(as: 'field', except: '')]
    public string $fieldId = '';

    #[Url(as: 'value', except: '')]
    public string $fieldValue = '';

    /** The team whose labels the filter may refer to. */
    abstract protected function filterTeamId(): int;

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    protected function applyTaskFilters(Builder $query): Builder
    {
        $labelId = $this->validLabelId();

        return $query
            ->when(trim($this->search) !== '', fn ($q) => $q->searchText($this->search))
            ->when(in_array($this->priority, Task::PRIORITIES, true), fn ($q) => $q->where('tasks.priority', $this->priority))
            ->when($labelId !== null, fn ($q) => $q->whereHas('labels', fn ($l) => $l->where('labels.id', $labelId)))
            ->when($this->fieldFilter() !== null, fn ($q) => $q->whereHas('fieldValues', fn ($v) => $v->where('task_field_id', $this->fieldFilter())->where('value', trim($this->fieldValue))))
            ->when(array_key_exists($this->due, self::DUE_STATES), fn ($q) => $q->dueState($this->due));
    }

    /** Only a label of this team counts; any other id is no filter at all. */
    protected function validLabelId(): ?int
    {
        if (! ctype_digit($this->labelId)) {
            return null;
        }

        $id = (int) $this->labelId;

        return Label::where('team_id', $this->filterTeamId())->whereKey($id)->exists() ? $id : null;
    }

    /** Only a field of this team counts, and only with something to look for. */
    protected function fieldFilter(): ?int
    {
        if (! ctype_digit($this->fieldId) || trim($this->fieldValue) === '') {
            return null;
        }

        return TaskField::where('team_id', $this->filterTeamId())->whereKey((int) $this->fieldId)->exists() ? (int) $this->fieldId : null;
    }

    protected function hasTaskFilters(): bool
    {
        return trim($this->search) !== ''
            || $this->fieldFilter() !== null
            || in_array($this->priority, Task::PRIORITIES, true)
            || $this->validLabelId() !== null
            || array_key_exists($this->due, self::DUE_STATES);
    }

    protected function resetTaskFilters(): void
    {
        $this->reset('search', 'priority', 'labelId', 'due', 'fieldId', 'fieldValue');
    }
}
