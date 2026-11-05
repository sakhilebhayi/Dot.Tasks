<?php

namespace App\Livewire\Tasks;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Services\InboxLists;
use App\Services\Notify\NotifyClient;
use App\Services\TaskHistory;
use App\Services\TaskSentenceReader;
use Illuminate\Support\Collection;
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

    public function save(): void
    {
        $read = $this->read();
        if ($read['title'] === '') {
            $this->addError('sentence', 'Say what needs doing.');

            return;
        }

        $list = $this->lists->firstWhere('id', (int) $this->listId) ?? abort(403);

        $task = Task::create([
            'task_list_id' => $list->id,
            'creator_id' => $this->user()->id,
            'assignee_id' => $read['assignee_id'],
            'title' => mb_substr($read['title'], 0, 255),
            'status' => 'todo',
            'priority' => $read['priority'] ?? 'medium',
            'due_date' => $read['due_date'],
            'sort_order' => (int) $list->tasks()->where('status', 'todo')->max('sort_order') + 1,
        ]);
        app(TaskHistory::class)->record($task, 'created', 'Created it');

        $assignee = $read['assignee_id'] ? User::find($read['assignee_id']) : null;
        if ($assignee && $assignee->id !== $this->user()->id) {
            $assignee->notify(new TaskAssignedNotification($task));
            app(NotifyClient::class)->taskAssigned($task, $assignee, $this->user());
        }

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
