<?php

namespace App\Livewire;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * What a person arriving from InfoDot sees first: the logo, a welcome by name, one action (write a task) and the
 * cards that are on their name right now. Every word and number comes from their own records; with none, it says so.
 */
class Landing extends Component
{
    /** Cards shown in the slot. The count beside the heading is of all of them. */
    public const SHOWN = 4;

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
     * Open work on this person's name, soonest due first, counted the way the Mine dashboard counts it.
     *
     * @return array{total: int, cards: Collection<int, Task>}
     */
    #[Computed]
    public function mine(): array
    {
        $open = fn () => Task::forTeam($this->user()->currentTeam)->mine($this->user())->where('tasks.status', '!=', 'done');

        return [
            'total' => $open()->count(),
            'cards' => $open()
                ->with(['taskList:id,team_id,name,is_inbox,owner_id', 'blockedBy:id,status'])
                ->orderByRaw('CASE WHEN tasks.due_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('tasks.due_date')
                ->orderByDesc('tasks.id')
                ->limit(self::SHOWN)
                ->get(),
        ];
    }

    /** A task was written or changed somewhere on the page: read the slot again. */
    #[On('task-created')]
    #[On('task-updated')]
    public function refreshSlot(): void
    {
        unset($this->mine);
    }

    public function render(): View
    {
        $user = $this->user();
        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url'), '/');

        return view('livewire.landing', [
            'firstName' => Str::of($user->name)->trim()->before(' ')->toString(),
            'company' => $user->currentTeam->name,
            'sharesBoards' => $user->can('create', TaskList::class),
            'infodotHome' => $infodot.'/home',
            'infodotPlatforms' => $infodot.'/platforms',
        ]);
    }
}
