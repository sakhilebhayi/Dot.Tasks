<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Services\InboxLists;
use App\Services\TaskTemplates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** The team's templates: made and changed by people who can edit tasks, used by anyone who can add to a board. */
class ManageTemplates extends Component
{
    public ?int $editing = null;

    public string $name = '';

    public string $title = '';

    public string $description = '';

    public string $priority = 'medium';

    public string $assignee = '';

    public string $dueIn = '';

    public string $subtasks = '';

    public string $checklist = '';

    public string $board = '';

    public string $notice = '';

    private function team(): Team
    {
        return auth()->user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->hasTeamPermission($this->team(), 'update');
    }

    /** @return Collection<int, TaskTemplate> */
    #[Computed]
    public function templates(): Collection
    {
        return TaskTemplate::where('team_id', $this->team()->id)->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return \Illuminate\Support\Collection<int, TaskList> where this person may make a task */
    #[Computed]
    public function boards()
    {
        app(InboxLists::class)->for(auth()->user(), $this->team());

        return $this->team()->taskLists()->orderByDesc('is_inbox')->orderBy('name')->get()
            ->filter(fn (TaskList $board) => Gate::allows('create', [Task::class, $board]))->values();
    }

    #[Computed]
    public function members()
    {
        return $this->team()->allUsers()->sortBy('name')->values();
    }

    private function template(int $id): TaskTemplate
    {
        return TaskTemplate::where('team_id', $this->team()->id)->find($id) ?? abort(404);
    }

    public function start(?int $id = null): void
    {
        abort_unless($this->canManage, 403);

        $this->resetErrorBag();
        $template = $id ? $this->template($id) : new TaskTemplate(['priority' => 'medium']);

        $this->editing = $id ?? 0;
        $this->name = $template->name ?? '';
        $this->title = $template->title ?? '';
        $this->description = $template->description ?? '';
        $this->priority = $template->priority ?? 'medium';
        $this->assignee = (string) ($template->assignee_id ?? '');
        $this->dueIn = (string) ($template->due_in_days ?? '');
        $this->subtasks = implode("\n", $template->subtasks ?? []);
        $this->checklist = implode("\n", $template->checklist ?? []);
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        abort_unless($this->canManage, 403);

        $this->name = trim($this->name);
        $this->title = trim($this->title);
        $this->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('task_templates', 'name')->where('team_id', $this->team()->id)->ignore($this->editing ?: null)],
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'dueIn' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'assignee' => ['nullable', Rule::in($this->members->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ], [], ['dueIn' => 'days until due', 'title' => 'task title']);

        $lines = fn (string $text) => collect(preg_split('/\R/', $text))->map(fn (string $line) => mb_substr(trim($line), 0, 255))->filter()->values()->all();

        $values = [
            'name' => $this->name, 'title' => $this->title, 'description' => trim($this->description) ?: null, 'priority' => $this->priority,
            'assignee_id' => $this->assignee ?: null, 'due_in_days' => $this->dueIn === '' ? null : (int) $this->dueIn,
            'subtasks' => $lines($this->subtasks), 'checklist' => $lines($this->checklist),
        ];

        if ($this->editing) {
            $this->template($this->editing)->update($values);
        } else {
            TaskTemplate::create($values + [
                'team_id' => $this->team()->id, 'created_by' => auth()->id(),
                'sort_order' => (int) TaskTemplate::where('team_id', $this->team()->id)->max('sort_order') + 1,
            ]);
        }

        $this->editing = null;
        unset($this->templates);
    }

    public function remove(int $id): void
    {
        abort_unless($this->canManage, 403);

        $this->template($id)->delete();

        unset($this->templates);
    }

    public function addStarters(): void
    {
        abort_unless($this->canManage, 403);

        $added = app(TaskTemplates::class)->addStarters($this->team(), auth()->user());

        $this->notice = $added === 1 ? 'Added 1 template.' : 'Added '.$added.' templates.';
        unset($this->templates);
    }

    /** Put templates in order, by hand or by keyboard. */
    public function placeTemplate(int $id, ?int $beforeId = null): void
    {
        abort_unless($this->canManage, 403);

        $template = $this->template($id);

        DB::transaction(function () use ($template, $beforeId) {
            $order = $this->templates->pluck('id')->reject(fn (int $other) => $other === $template->id)->values();
            $at = $beforeId === null ? false : $order->search($beforeId);
            $order->splice($at === false ? $order->count() : $at, 0, [$template->id]);

            foreach ($order as $index => $other) {
                TaskTemplate::whereKey($other)->update(['sort_order' => $index + 1]);
            }
        });

        unset($this->templates);
    }

    /** Make the task on the chosen board and open it. */
    public function use(int $id): void
    {
        $board = $this->boards->firstWhere('id', (int) $this->board) ?? $this->boards->first() ?? abort(403);

        $task = app(TaskTemplates::class)->apply($this->template($id), $board, auth()->user());

        $this->notice = 'Made "'.$task->title.'" on '.($board->is_inbox ? 'your Inbox' : $board->name).'.';
        $this->dispatch('open-task', taskId: $task->id);
    }

    public function render(): View
    {
        return view('livewire.tasks.manage-templates');
    }
}
