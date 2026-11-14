<?php

namespace App\Livewire;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Support\Today;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What a person arriving from InfoDot sees first: the logo, one sentence that is true of their work today, a welcome by
 * name and one action (write a task). Every word and number comes from their own records; with none, it says so.
 */
class Landing extends Component
{
    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }

    public function mount(): void
    {
        abort_unless($this->user()->currentTeam, 403);
    }

    /**
     * The three counts the arrival sentence is built from, taken the way the Mine dashboard counts them: open work on this
     * person's name, how much of it is late, and how much is due today (the team's calendar day).
     *
     * @return array{open: int, overdue: int, today: int}
     */
    #[Computed]
    public function state(): array
    {
        $today = Today::date();
        $open = fn () => Task::forTeam($this->user()->currentTeam)->mine($this->user())->where('tasks.status', '!=', 'done');

        return [
            'open' => $open()->count(),
            'overdue' => $open()->whereDate('tasks.due_date', '<', $today)->count(),
            'today' => $open()->whereDate('tasks.due_date', $today)->count(),
        ];
    }

    /**
     * The cards of the arrival rack: up to four of this person's own open tasks, the earliest due first (late ones lead,
     * undated ones last). The picture is drawn from these rows, so it never shows work that is not theirs.
     *
     * @return list<array{state: string, priority: string, title: int}>
     */
    #[Computed]
    public function rack(): array
    {
        $today = Today::date();

        return Task::forTeam($this->user()->currentTeam)->mine($this->user())
            ->where('tasks.status', '!=', 'done')
            ->orderByRaw('tasks.due_date IS NULL')->orderBy('tasks.due_date')->orderByPriorityRank()->orderBy('tasks.id')
            ->limit(4)->get(['tasks.id', 'tasks.title', 'tasks.due_date', 'tasks.priority'])
            ->map(fn (Task $task) => [
                'state' => match (true) {
                    $task->due_date === null => 'open',
                    $task->due_date->lt($today) => 'late',
                    $task->due_date->isSameDay($today) => 'today',
                    default => 'open',
                },
                'priority' => (string) $task->priority,
                'title' => mb_strlen((string) $task->title),
            ])->all();
    }

    /** A task was written or changed somewhere on the page: read the counts again. */
    #[On('task-created')]
    #[On('task-updated')]
    public function refreshState(): void
    {
        unset($this->state, $this->rack);
    }

    public function render(): View
    {
        $user = $this->user();
        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');

        $state = $this->state;
        $late = array_filter([
            $state['overdue'] > 0 ? $state['overdue'].' overdue' : null,
            $state['today'] > 0 ? $state['today'].' due today' : null,
        ]);

        // One sentence that is true now. With nothing on their name it has nothing to count, so it says that and no more.
        $sentence = match (true) {
            $state['open'] === 0 => 'Nothing is on your name.',
            $late !== [] => implode(', ', $late).'.',
            default => 'Nothing is late. '.$state['open'].' '.($state['open'] === 1 ? 'is' : 'are').' open.',
        };

        return view('livewire.landing', [
            'sentence' => $sentence,
            'isEmpty' => $state['open'] === 0,
            'rack' => $this->rack,
            'open' => $state['open'],
            'firstName' => Str::of($user->name)->trim()->before(' ')->toString(),
            'company' => $user->currentTeam->name,
            'sharesBoards' => $user->can('create', TaskList::class),
            'infodotHome' => $infodot.'/home',
            'infodotPlatforms' => $infodot.'/platforms',
        ]);
    }
}
