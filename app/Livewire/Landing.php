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

    /** A task was written or changed somewhere on the page: read the counts again. */
    #[On('task-created')]
    #[On('task-updated')]
    public function refreshState(): void
    {
        unset($this->state);
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
            'open' => $state['open'],
            'firstName' => Str::of($user->name)->trim()->before(' ')->toString(),
            'company' => $user->currentTeam->name,
            'sharesBoards' => $user->can('create', TaskList::class),
            'infodotHome' => $infodot.'/home',
            'infodotPlatforms' => $infodot.'/platforms',
        ]);
    }
}
