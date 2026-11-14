<?php

namespace App\Livewire;

use App\Models\DashboardLayout;
use App\Models\Task;
use App\Models\Team;
use App\Services\DashboardFigures;
use App\Services\DashboardStats;
use App\Services\RemovedTaskService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Three views of the same records: my work, the team's, and the business as a whole. Each person arranges their own. */
class Dashboard extends Component
{
    /** Widget ids stay put, so a saved layout keeps working if the labels change. */
    public const WIDGETS = [
        // The order here is the order everyone starts with: what is late, what is stuck, what is due, then the rest.
        'personal' => [
            1 => ['plan', 'My Day'], 2 => ['overdue', 'Overdue'], 5 => ['blocked', 'Blocked'], 3 => ['due_soon', 'Due in the next 2 days'],
            9 => ['no_date', 'No due date'], 4 => ['awaiting', 'Waiting for my response'], 6 => ['high', 'High priority'],
            7 => ['upcoming', 'Coming up'], 8 => ['recent', 'Recently finished'],
        ],
        'team' => [
            11 => ['workload', 'Who holds what'], 12 => ['status', 'Tasks by status'], 13 => ['trend', 'Finished each week'], 14 => ['attention', 'Needs a manager'],
            15 => ['overdue', 'Overdue'], 16 => ['blocked', 'Blocked'], 17 => ['upcoming', 'Due in 7 days'], 18 => ['unassigned', 'Nobody on it'],
        ],
        'business' => [
            21 => ['boards', 'Boards'], 22 => ['trend', 'Finished each week'], 23 => ['status', 'Tasks by status'],
        ],
    ];

    /** The groups that sit in the narrow column beside the main one. Everything else is main. */
    public const SIDE = ['plan', 'upcoming', 'recent', 'workload', 'status', 'trend'];

    /** Groups that list tasks: the one-listing rule applies to them, and an empty one folds into the "Also checked" line. */
    private const TASK_GROUPS = ['overdue', 'blocked', 'due_soon', 'no_date', 'awaiting', 'high', 'attention', 'unassigned'];

    #[Url(except: 'personal')]
    public string $view = 'personal';

    /** A board to narrow the team view to. */
    #[Url(except: '')]
    public string $board = '';

    public bool $arranging = false;

    private function team(): Team
    {
        return auth()->user()->currentTeam;
    }

    public function mount(): void
    {
        abort_unless($this->team(), 403);

        if (! in_array($this->view, $this->views, true)) {
            $this->view = 'personal';
        }
    }

    /** @return list<string> */
    #[Computed]
    public function views(): array
    {
        return auth()->user()->hasTeamPermission($this->team(), 'delete') ? ['personal', 'team', 'business'] : ['personal', 'team'];
    }

    #[Computed]
    public function boards()
    {
        return $this->team()->boards()->orderBy('name')->get(['id', 'name']);
    }

    private function boardId(): ?int
    {
        return ctype_digit($this->board) && $this->boards->contains('id', (int) $this->board) ? (int) $this->board : null;
    }

    /** Whether the Team view is narrowed to one real board of this team; a made-up value narrows nothing. */
    #[Computed]
    public function narrowed(): bool
    {
        return $this->boardId() !== null;
    }

    /** @return array<string, int> */
    #[Computed]
    public function counts(): array
    {
        return app(DashboardStats::class)->forTeam($this->team());
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function data(): array
    {
        $figures = app(DashboardFigures::class);

        return match ($this->view) {
            'team' => $figures->team($this->team(), $this->boardId()),
            'business' => $figures->business($this->team()),
            default => $figures->personal(auth()->user()),
        };
    }

    /** Boards with the work still open on each, for the Boards list beside the Mine view. */
    #[Computed]
    public function boardRows()
    {
        return $this->team()->boards()
            ->withCount(['tasks as open_count' => fn ($q) => $q->where('tasks.status', '!=', 'done')])
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return array{visible: int, removed: int} what the foot line counts: tasks this person can see, and removed tasks */
    #[Computed]
    public function foot(): array
    {
        return [
            'visible' => Task::forTeam($this->team())->count(),
            'removed' => app(RemovedTaskService::class)->query($this->team())->count(),
        ];
    }

    /**
     * What each visible group shows. The one-listing rule lives here, in the view layer: a task already shown by an earlier
     * group is skipped in the later ones, so it is read once on the page. The figures themselves are untouched.
     *
     * @return array{groups: list<array<string, mixed>>, checked: list<string>}
     */
    #[Computed]
    public function sections(): array
    {
        $claimed = [];
        $checked = [];
        $groups = [];

        foreach ($this->widgets as $widget) {
            if ($widget['hidden']) {
                continue;
            }

            $key = $widget['key'];
            $tasks = null;
            $name = $widget['name'];

            if (in_array($key, [...self::TASK_GROUPS, 'upcoming', 'recent'], true)) {
                $all = $this->data[$key];
                $tasks = in_array($key, self::TASK_GROUPS, true) ? $all->reject(fn ($task) => isset($claimed[$task->id]))->values() : $all;

                if ($this->arranging === false && $tasks->isEmpty() && in_array($key, self::TASK_GROUPS, true)) {
                    $checked[] = $name;

                    continue;
                }

                if ($this->view === 'team' && $key === 'overdue' && $claimed !== []) {
                    $name = 'Overdue, not listed above';
                }

                if (in_array($key, self::TASK_GROUPS, true)) {
                    foreach ($tasks as $task) {
                        $claimed[$task->id] = true;
                    }
                }
            }

            $groups[] = array_merge($widget, ['name' => $name, 'tasks' => $tasks, 'side' => in_array($key, self::SIDE, true)]);
        }

        return ['groups' => $groups, 'checked' => $checked];
    }

    /** This person's own arrangement, or the team's, or the one everyone starts with. @return array{order: list<int>, hidden: list<int>} */
    #[Computed]
    public function layout(): array
    {
        $saved = DashboardLayout::where('team_id', $this->team()->id)->where('view', $this->view)
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->orderByRaw('CASE WHEN user_id IS NULL THEN 1 ELSE 0 END')->first();

        $known = array_keys(self::WIDGETS[$this->view]);
        $order = array_values(array_intersect($saved->layout['order'] ?? [], $known));

        return ['order' => [...$order, ...array_diff($known, $order)], 'hidden' => array_values(array_intersect($saved->layout['hidden'] ?? [], $known))];
    }

    /** @return list<array{id: int, key: string, name: string, hidden: bool}> */
    #[Computed]
    public function widgets(): array
    {
        return array_map(fn (int $id) => [
            'id' => $id,
            'key' => self::WIDGETS[$this->view][$id][0],
            'name' => self::WIDGETS[$this->view][$id][1],
            'hidden' => in_array($id, $this->layout['hidden'], true),
        ], $this->layout['order']);
    }

    private function save(array $layout, ?int $user): void
    {
        DashboardLayout::updateOrCreate(['team_id' => $this->team()->id, 'user_id' => $user, 'view' => $this->view], ['layout' => $layout]);

        unset($this->layout, $this->widgets, $this->sections);
    }

    public function placeWidget(int $id, ?int $beforeId = null): void
    {
        abort_unless(in_array($id, $this->layout['order'], true), 404);

        $order = array_values(array_diff($this->layout['order'], [$id]));
        $at = $beforeId === null ? false : array_search($beforeId, $order, true);
        array_splice($order, $at === false ? count($order) : $at, 0, [$id]);

        $this->save(['order' => $order, 'hidden' => $this->layout['hidden']], auth()->id());
    }

    public function toggleWidget(int $id): void
    {
        abort_unless(in_array($id, $this->layout['order'], true), 404);

        $hidden = in_array($id, $this->layout['hidden'], true) ? array_values(array_diff($this->layout['hidden'], [$id])) : [...$this->layout['hidden'], $id];

        $this->save(['order' => $this->layout['order'], 'hidden' => $hidden], auth()->id());
    }

    /** Back to what the team starts from. */
    public function resetLayout(): void
    {
        DashboardLayout::where('team_id', $this->team()->id)->where('view', $this->view)->where('user_id', auth()->id())->delete();

        unset($this->layout, $this->widgets, $this->sections);
    }

    /** Admins can make this arrangement the one the team starts from. */
    public function shareLayout(): void
    {
        abort_unless(auth()->user()->hasTeamPermission($this->team(), 'delete'), 403);

        $this->save($this->layout, null);
    }

    #[On('task-created')]
    #[On('task-updated')]
    public function refreshBoard(): void
    {
        unset($this->data, $this->sections, $this->counts, $this->boardRows, $this->foot);
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }
}
