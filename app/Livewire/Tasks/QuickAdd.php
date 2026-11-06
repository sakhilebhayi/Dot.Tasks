<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\InboxLists;
use App\Services\TaskEditor;
use App\Services\TaskHistory;
use App\Services\TaskSentenceReader;
use App\Services\TaskTemplates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Make a task from one sentence, from anywhere. What was understood is shown before anything is created, and each
 * part can be changed, so nothing is guessed on the person's behalf.
 */
class QuickAdd extends Component
{
    public bool $open = false;

    public string $sentence = '';

    /** The board being looked at, offered first. */
    #[Locked]
    public ?int $board = null;

    public string $listId = '';

    public string $templateId = '';

    /** The task just made, so the person can see where it went. */
    public ?int $addedId = null;

    public string $addedTo = '';

    public function mount(?int $board = null): void
    {
        $this->board = $board;
    }

    #[On('open-quick-add')]
    public function show(): void
    {
        $this->open = true;
        $this->sentence = '';
        $this->templateId = '';
        $this->resetErrorBag();
        $this->listId = (string) ($this->board && $this->lists->contains('id', $this->board) ? $this->board : app(InboxLists::class)->for($this->user(), $this->user()->currentTeam)->id);
    }

    public function close(): void
    {
        $this->open = false;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    /** @return Collection<int, TaskList> where this person may add a task */
    #[Computed]
    public function lists(): Collection
    {
        $team = $this->user()->currentTeam;
        if (! $team) {
            return collect();
        }

        return $team->taskLists()
            ->orderByDesc('is_inbox')->orderBy('name')->get()
            ->filter(fn (TaskList $list) => $this->user()->can('create', [Task::class, $list]))
            ->values();
    }

    /** @return array{title: string, due_date: string|null, priority: string|null, assignee_id: int|null, assignee_name: string|null, unknown_person: string|null} */
    #[Computed]
    public function read(): array
    {
        return app(TaskSentenceReader::class)->read($this->sentence, $this->user()->currentTeam);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, TaskTemplate> */
    #[Computed]
    public function templates()
    {
        return TaskTemplate::where('team_id', $this->user()->current_team_id)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
    }

    public function save(): void
    {
        $template = $this->templateId === '' ? null : ($this->templates->firstWhere('id', (int) $this->templateId) ?? abort(404));
        if ($template) {
            $this->saveFromTemplate(TaskTemplate::find($template->id));

            return;
        }

        $read = $this->read();
        if ($read['title'] === '') {
            $this->addError('sentence', 'Say what needs doing.');

            return;
        }

        $list = $this->lists->firstWhere('id', (int) $this->listId) ?? abort(403);

        // Together, so a rule that fires on a new task sees it with its person already on it.
        $task = DB::transaction(function () use ($list, $read) {
            $task = Task::create([
                'task_list_id' => $list->id,
                'creator_id' => $this->user()->id,
                'title' => mb_substr($read['title'], 0, 255),
                'status' => 'todo',
                'priority' => $read['priority'] ?? 'medium',
                'due_date' => $read['due_date'],
                'sort_order' => (int) $list->tasks()->where('status', 'todo')->max('sort_order') + 1,
            ]);
            app(TaskHistory::class)->record($task, 'created', 'Created it');

            if ($read['assignee_id']) {
                app(TaskEditor::class)->assign($task, User::find($read['assignee_id']), $this->user());
            }

            return $task;
        });

        $this->added($task, $list);
    }

    /** What is typed names this one. Nothing typed keeps the template's own title. */
    private function saveFromTemplate(TaskTemplate $template): void
    {
        $list = $this->lists->firstWhere('id', (int) $this->listId) ?? abort(403);

        $this->added(app(TaskTemplates::class)->apply($template, $list, $this->user(), trim($this->sentence) ?: null), $list);
    }

    private function added(Task $task, TaskList $list): void
    {
        $this->open = false;
        $this->sentence = '';
        $this->addedId = $task->id;
        $this->addedTo = $list->is_inbox ? 'your Inbox' : $list->name;
        $this->dispatch('task-created');
    }

    public function render(): View
    {
        return view('livewire.tasks.quick-add');
    }
}
