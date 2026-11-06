<?php

namespace App\Livewire\Tasks;

use App\Models\Label;
use App\Models\TaskField;
use App\Models\TaskRule;
use App\Models\TaskRuleRun;
use App\Models\Team;
use App\Services\AutomationCatalog;
use App\Services\Automations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The team's rules. Everyone on the team can read them and what they did. Admins and the owner write them, because a rule
 * acts as the person who wrote it. A rule starts off, and says in a sentence what it does before anyone turns it on.
 */
class ManageRules extends Component
{
    /** The rule open in the form: 0 for a new one, null for none. */
    public ?int $editing = null;

    public string $name = '';

    public string $board = '';

    public string $trigger = 'created';

    public string $match = 'all';

    /** @var list<array{field: string, op: string, value: string}> */
    public array $conditions = [];

    /** @var list<array{type: string, value: string}> */
    public array $actions = [];

    public int|string $delay = 0;

    public bool $approval = false;

    private function team(): Team
    {
        return auth()->user()->currentTeam;
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()->hasTeamPermission($this->team(), 'delete');
    }

    private function mustManage(): void
    {
        abort_unless($this->canManage, 403);
    }

    private function rule(int $id): TaskRule
    {
        return TaskRule::where('team_id', $this->team()->id)->find($id) ?? abort(404);
    }

    private function run(int $id): TaskRuleRun
    {
        return TaskRuleRun::whereHas('rule', fn ($q) => $q->where('team_id', $this->team()->id))->find($id) ?? abort(404);
    }

    /** @return Collection<int, TaskRule> */
    #[Computed]
    public function rules(): Collection
    {
        return TaskRule::where('team_id', $this->team()->id)->with('author:id,name')->latest('id')->get();
    }

    /** @return Collection<int, TaskRuleRun> */
    #[Computed]
    public function runs(): Collection
    {
        return TaskRuleRun::whereHas('rule', fn ($q) => $q->where('team_id', $this->team()->id))
            ->with(['rule:id,name', 'task:id,title,task_list_id'])
            ->orderByRaw("CASE status WHEN 'needs_approval' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END")
            ->latest('id')->limit(40)->get();
    }

    #[Computed]
    public function members()
    {
        return $this->team()->allUsers()->sortBy('name')->values();
    }

    #[Computed]
    public function labels()
    {
        return Label::where('team_id', $this->team()->id)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function customFields()
    {
        return TaskField::where('team_id', $this->team()->id)->orderBy('sort_order')->get(['id', 'name']);
    }

    #[Computed]
    public function boards()
    {
        return $this->team()->boards()->orderBy('name')->get(['id', 'name']);
    }

    private function form(): array
    {
        return ['name' => $this->name, 'board' => $this->board, 'trigger' => $this->trigger, 'match' => $this->match, 'conditions' => $this->conditions, 'actions' => $this->actions, 'delay' => $this->delay, 'approval' => $this->approval];
    }

    /** The rule as it stands in the form, in one sentence, or what is still missing. */
    #[Computed]
    public function preview(): string
    {
        try {
            $rule = new TaskRule(app(AutomationCatalog::class)->clean(['name' => $this->name ?: 'Unnamed'] + $this->form(), $this->team()));
            $rule->team_id = $this->team()->id;

            return app(AutomationCatalog::class)->describe($rule);
        } catch (ValidationException $missing) {
            return $missing->validator->errors()->first();
        }
    }

    public function start(?int $id = null): void
    {
        $this->mustManage();
        $this->resetErrorBag();

        $rule = $id ? $this->rule($id) : null;
        $this->editing = $id ?? 0;
        $this->name = $rule->name ?? '';
        $this->board = (string) ($rule->task_list_id ?? '');
        $this->trigger = $rule->trigger ?? 'created';
        $this->match = $rule->match ?? 'all';
        $this->conditions = $rule->conditions ?? [];
        $this->actions = $rule->actions ?? [['type' => 'notify', 'value' => 'assignee']];
        $this->delay = $rule->delay_minutes ?? 0;
        $this->approval = $rule->needs_approval ?? false;
    }

    public function cancel(): void
    {
        $this->editing = null;
    }

    public function addCondition(): void
    {
        $this->conditions[] = ['field' => 'priority', 'op' => 'is', 'value' => 'urgent'];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    public function addAction(): void
    {
        $this->actions[] = ['type' => 'notify', 'value' => 'assignee'];
    }

    public function removeAction(int $index): void
    {
        unset($this->actions[$index]);
        $this->actions = array_values($this->actions);
    }

    /** Starts off when new. A rule changed while on keeps running, now as the person who changed it. */
    public function save(): void
    {
        $this->mustManage();

        $values = app(AutomationCatalog::class)->clean($this->form(), $this->team()) + ['created_by' => auth()->id()];

        if ($this->editing) {
            $this->rule($this->editing)->update($values);
        } else {
            TaskRule::create($values + ['team_id' => $this->team()->id, 'enabled' => false]);
        }

        $this->editing = null;
        unset($this->rules);
    }

    public function toggle(int $id): void
    {
        $this->mustManage();

        $rule = $this->rule($id);
        $rule->update(['enabled' => ! $rule->enabled]);

        unset($this->rules);
    }

    public function remove(int $id): void
    {
        $this->mustManage();

        $this->rule($id)->delete();

        unset($this->rules, $this->runs);
    }

    public function approveRun(int $id): void
    {
        $this->mustManage();

        app(Automations::class)->approve($this->run($id), auth()->user());

        unset($this->runs);
    }

    public function declineRun(int $id): void
    {
        $this->mustManage();

        app(Automations::class)->decline($this->run($id), auth()->user());

        unset($this->runs);
    }

    public function retryRun(int $id): void
    {
        $this->mustManage();

        app(Automations::class)->retry($this->run($id));

        unset($this->runs);
    }

    public function render(): View
    {
        return view('livewire.tasks.manage-rules', [
            'catalog' => app(AutomationCatalog::class),
            'automations' => app(Automations::class),
        ]);
    }
}
