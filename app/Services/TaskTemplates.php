<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskList;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Make a template from a task, make a task from a template, and offer a team some to start from. Callers authorize first. */
class TaskTemplates
{
    /** Common shapes of work, offered when a team asks for them. Generic on purpose: a team edits them to fit. */
    private const STARTERS = [
        ['Employee onboarding', 'Onboard a new employee', 14, ['Prepare the contract', 'Set up equipment and access', 'Plan the first week', 'Introduce the team'], ['Contract signed', 'Banking details received', 'Safety induction done']],
        ['Monthly reporting', 'Prepare the monthly report', 5, ['Collect the figures', 'Draft the report', 'Review with the manager', 'Send it out'], ['Figures checked', 'Previous actions followed up']],
        ['Equipment inspection', 'Inspect equipment', 3, ['Inspect the equipment', 'Record what was found', 'Log any repairs needed'], ['Safety checks done', 'Inspection sheet filed']],
        ['Procurement request', 'Request a purchase', 7, ['Define what is needed', 'Get quotes', 'Get approval', 'Place the order'], ['Budget confirmed', 'Three quotes attached']],
        ['Incident investigation', 'Investigate an incident', 3, ['Secure the scene', 'Gather statements', 'Find the cause', 'Agree corrective actions'], ['Incident reported', 'Witnesses recorded', 'Actions assigned']],
        ['Project handover', 'Hand over a project', 10, ['List what is complete', 'List what is open', 'Walk through it with the new owner'], ['Documents shared', 'Access transferred']],
        ['Maintenance preparation', 'Prepare for maintenance', 7, ['Plan the work', 'Order the parts', 'Schedule the shutdown', 'Brief the team'], ['Permits in place', 'Parts on site']],
        ['Customer follow-up', 'Follow up with a customer', 2, ['Review the last contact', 'Call the customer', 'Record the outcome'], ['Next step agreed']],
    ];

    public function __construct(private readonly TaskHistory $history) {}

    public function fromTask(Task $task, string $name, User $by): TaskTemplate
    {
        $team = $task->taskList->team;

        return TaskTemplate::create([
            'team_id' => $team->id,
            'created_by' => $by->id,
            'name' => $name,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority ?? 'medium',
            'assignee_id' => $task->assignee_id,
            'due_in_days' => $task->due_date && $task->created_at ? max(0, (int) $task->created_at->startOfDay()->diffInDays($task->due_date, false)) : null,
            'estimated_minutes' => $task->estimated_minutes,
            'subtasks' => $task->subtasks->pluck('title')->all(),
            'checklist' => $task->checklistItems->pluck('title')->all(),
            'label_ids' => $task->labels->pluck('id')->all(),
            'sort_order' => (int) TaskTemplate::where('team_id', $team->id)->max('sort_order') + 1,
        ]);
    }

    public function apply(TaskTemplate $template, TaskList $board, User $by, ?string $title = null): Task
    {
        return DB::transaction(function () use ($template, $board, $by, $title) {
            $team = $board->team;
            $assignee = $template->assignee_id && $team->hasUser(User::find($template->assignee_id) ?? new User) ? $template->assignee_id : null;

            $task = Task::create([
                'task_list_id' => $board->id,
                'creator_id' => $by->id,
                'title' => $title ?: $template->title,
                'description' => $template->description,
                'status' => 'todo',
                'priority' => $template->priority,
                'due_date' => $template->due_in_days === null ? null : Carbon::today()->addDays($template->due_in_days),
                'estimated_minutes' => $template->estimated_minutes,
                'sort_order' => (int) $board->tasks()->where('status', 'todo')->max('sort_order') + 1,
            ]);

            foreach (array_values($template->subtasks ?? []) as $order => $subtask) {
                Task::create(['task_list_id' => $board->id, 'parent_id' => $task->id, 'creator_id' => $by->id, 'title' => $subtask, 'status' => 'todo', 'priority' => 'medium', 'sort_order' => $order + 1]);
            }

            foreach (array_values($template->checklist ?? []) as $order => $line) {
                $task->checklistItems()->create(['title' => $line, 'sort_order' => $order + 1]);
            }

            $task->labels()->sync($team->labels()->whereIn('id', $template->label_ids ?? [])->pluck('id'));
            $this->history->record($task, 'created', 'Made it from the template '.$template->name);

            if ($assignee) {
                app(TaskEditor::class)->assign($task, User::find($assignee), $by);
            }

            return $task;
        });
    }

    /** Adds what the team does not have yet, so asking twice changes nothing. */
    public function addStarters(Team $team, User $by): int
    {
        $have = TaskTemplate::where('team_id', $team->id)->pluck('name');
        $order = (int) TaskTemplate::where('team_id', $team->id)->max('sort_order');
        $added = 0;

        foreach (self::STARTERS as [$name, $title, $days, $subtasks, $checklist]) {
            if ($have->contains($name)) {
                continue;
            }

            TaskTemplate::create([
                'team_id' => $team->id, 'created_by' => $by->id, 'name' => $name, 'title' => $title, 'priority' => 'medium',
                'due_in_days' => $days, 'subtasks' => $subtasks, 'checklist' => $checklist, 'sort_order' => ++$order,
            ]);
            $added++;
        }

        return $added;
    }
}
