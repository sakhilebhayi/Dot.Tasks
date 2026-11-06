<?php

namespace App\Services;

use App\Models\Label;
use App\Models\TaskField;
use App\Models\TaskList;
use App\Models\TaskRule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** What a rule can say, how it reads in plain words, and whether what it says is real on this team. */
class AutomationCatalog
{
    public const TRIGGERS = [
        'created' => 'a task is created',
        'moved' => 'a task moves to another column',
        'assigned' => 'a task is given to someone',
        'completed' => 'a task is finished',
        'overdue' => 'a task becomes overdue',
        'blocked' => 'a task becomes blocked',
        'unblocked' => 'a task is no longer held up',
        'subtasks_done' => 'all the subtasks of a task are finished',
        'approved' => 'a task is approved',
    ];

    public const FIELDS = ['priority' => 'Priority', 'status' => 'Status', 'assignee' => 'Person', 'label' => 'Label', 'title' => 'Title'];

    public const OPERATORS = ['is' => 'is', 'is_not' => 'is not', 'contains' => 'contains', 'empty' => 'is empty', 'filled' => 'is not empty'];

    public const ACTIONS = [
        'assign' => 'Give it to',
        'priority' => 'Set the priority to',
        'move' => 'Move it to',
        'label' => 'Add the label',
        'due' => 'Make it due in this many days',
        'notify' => 'Notify',
        'comment' => 'Add a comment',
        'approval' => 'Ask for approval from',
    ];

    public const MOVES = ['next' => 'the next column', 'todo' => 'to do', 'in_progress' => 'in progress', 'review' => 'review', 'done' => 'done'];

    private const MOST_ROWS = 8;

    /** @param array{name: string, board: mixed, trigger: string, match: string, conditions: array, actions: array, delay: mixed, approval: bool} $form
     * @return array<string, mixed> values ready for a rule
     *
     * @throws ValidationException (key 'rule') */
    public function clean(array $form, Team $team): array
    {
        $refuse = fn (string $why) => throw ValidationException::withMessages(['rule' => $why]);

        $name = trim($form['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 120) {
            $refuse('Give the rule a name of up to 120 characters.');
        }

        if (! array_key_exists($form['trigger'] ?? '', self::TRIGGERS)) {
            $refuse('Pick what sets the rule off.');
        }

        $board = ($form['board'] ?? '') === '' ? null : (int) $form['board'];
        if ($board && ! $team->boards()->whereKey($board)->exists()) {
            $refuse('That board is not on this team.');
        }

        $conditions = array_values($form['conditions'] ?? []);
        $actions = array_values($form['actions'] ?? []);
        if ($actions === []) {
            $refuse('A rule has to do something: add an action.');
        }

        if (count($conditions) > self::MOST_ROWS || count($actions) > self::MOST_ROWS) {
            $refuse('A rule can have up to '.self::MOST_ROWS.' conditions and '.self::MOST_ROWS.' actions.');
        }

        $delay = $form['delay'] ?? 0;
        if (! is_numeric($delay) || $delay < 0 || $delay > 43200) {
            $refuse('A delay is up to 30 days, in minutes.');
        }

        return [
            'name' => $name,
            'task_list_id' => $board,
            'trigger' => $form['trigger'],
            'match' => ($form['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditions' => array_map(fn (array $row) => $this->cleanCondition($row, $team), $conditions),
            'actions' => array_map(fn (array $row) => $this->cleanAction($row, $team), $actions),
            'delay_minutes' => (int) $delay,
            'needs_approval' => (bool) ($form['approval'] ?? false),
        ];
    }

    /** @param array<string, mixed> $row */
    private function cleanCondition(array $row, Team $team): array
    {
        $field = (string) ($row['field'] ?? '');
        $op = (string) ($row['op'] ?? 'is');
        $value = trim((string) ($row['value'] ?? ''));

        $known = array_key_exists($field, self::FIELDS) || (str_starts_with($field, 'f') && TaskField::where('team_id', $team->id)->whereKey((int) substr($field, 1))->exists());
        if (! $known || ! array_key_exists($op, self::OPERATORS)) {
            throw ValidationException::withMessages(['rule' => 'One of the conditions is not complete.']);
        }

        if ($value === '' && ! in_array($op, ['empty', 'filled'], true)) {
            throw ValidationException::withMessages(['rule' => 'One of the conditions has nothing to compare with.']);
        }

        return ['field' => $field, 'op' => $op, 'value' => $value];
    }

    /** @param array<string, mixed> $row */
    private function cleanAction(array $row, Team $team): array
    {
        $type = (string) ($row['type'] ?? '');
        $value = trim((string) ($row['value'] ?? ''));

        $fits = match ($type) {
            'assign', 'approval' => $team->allUsers()->contains('id', (int) $value),
            'priority' => in_array($value, ['low', 'medium', 'high', 'urgent'], true),
            'move' => array_key_exists($value, self::MOVES),
            'label' => Label::where('team_id', $team->id)->whereKey((int) $value)->exists(),
            'due' => ctype_digit($value) && (int) $value <= 3650,
            'notify' => in_array($value, ['assignee', 'creator'], true) || $team->allUsers()->contains('id', (int) $value),
            'comment' => $value !== '' && mb_strlen($value) <= 2000,
            default => false,
        };

        if (! $fits) {
            throw ValidationException::withMessages(['rule' => 'One of the actions is not complete.']);
        }

        return ['type' => $type, 'value' => $value];
    }

    /** The whole rule as one sentence, which is what a person reads before turning it on. */
    public function describe(TaskRule $rule): string
    {
        $sentence = 'When '.(self::TRIGGERS[$rule->trigger] ?? $rule->trigger);

        if ($rule->task_list_id) {
            $sentence .= ' on '.(TaskList::withArchived()->find($rule->task_list_id)?->name ?? 'a board that has gone');
        }

        if ($rule->conditions !== []) {
            $parts = array_map(fn (array $row) => $this->saysCondition($row, $rule->team_id), $rule->conditions);
            $sentence .= ', if '.Str::of(implode($rule->match === 'any' ? ' or ' : ' and ', $parts));
        }

        if ($rule->delay_minutes > 0) {
            $sentence .= ', wait '.$this->saysDelay($rule->delay_minutes);
        }

        if ($rule->needs_approval) {
            $sentence .= ', ask an admin to approve';
        }

        $did = array_map(fn (array $row) => $this->saysAction($row), $rule->actions);

        return $sentence.', then '.$this->listed($did).'.';
    }

    /** @param array{field: string, op: string, value: string} $row */
    private function saysCondition(array $row, int $team): string
    {
        $name = self::FIELDS[$row['field']] ?? TaskField::where('team_id', $team)->find((int) substr($row['field'], 1))?->name ?? 'a field that has gone';
        $word = self::OPERATORS[$row['op']];

        if (in_array($row['op'], ['empty', 'filled'], true)) {
            return mb_strtolower($name).' '.$word;
        }

        $value = match ($row['field']) {
            'assignee' => User::find((int) $row['value'])?->name ?? 'someone who has gone',
            'label' => Label::find((int) $row['value'])?->name ?? 'a label that has gone',
            default => str_replace('_', ' ', $row['value']),
        };

        return mb_strtolower($name).' '.$word.' '.$value;
    }

    /** @param array{type: string, value: string} $row */
    private function saysAction(array $row): string
    {
        $person = fn () => User::find((int) $row['value'])?->name ?? 'someone who has gone';

        return match ($row['type']) {
            'assign' => 'give it to '.$person(),
            'priority' => 'set the priority to '.$row['value'],
            'move' => 'move it to '.self::MOVES[$row['value']],
            'label' => 'add the label '.(Label::find((int) $row['value'])?->name ?? 'that has gone'),
            'due' => 'make it due in '.$row['value'].' days',
            'notify' => 'notify '.(in_array($row['value'], ['assignee', 'creator'], true) ? 'the '.($row['value'] === 'assignee' ? 'person on the task' : 'person who made it') : $person()),
            'comment' => 'add the comment "'.Str::limit($row['value'], 60).'"',
            'approval' => 'ask '.$person().' to approve it',
            default => $row['type'],
        };
    }

    private function saysDelay(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => ($minutes / 1440).' '.Str::plural('day', $minutes / 1440),
            $minutes % 60 === 0 => ($minutes / 60).' '.Str::plural('hour', $minutes / 60),
            default => $minutes.' '.Str::plural('minute', $minutes),
        };
    }

    /** @param list<string> $parts */
    private function listed(array $parts): string
    {
        $last = array_pop($parts);

        return $parts === [] ? (string) $last : implode(', ', $parts).' and '.$last;
    }
}
