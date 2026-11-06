<?php

namespace App\Observers;

use App\Models\TaskField;
use App\Models\TaskList;
use App\Models\TaskRule;
use App\Models\TaskStatus;
use App\Models\TaskTemplate;
use App\Services\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Every change to how a board or a business is set up, whichever screen or job made it. */
class AuditedSetup
{
    private const NAMES = [TaskField::class => 'field', TaskList::class => 'board', TaskRule::class => 'rule', TaskStatus::class => 'column', TaskTemplate::class => 'template'];

    public function created(Model $model): void
    {
        $this->log($model, 'added', 'Added the');
    }

    public function updated(Model $model): void
    {
        $changed = array_diff(array_keys($model->getChanges()), ['updated_at', 'sort_order']);

        if ($model instanceof TaskList && in_array('archived_at', $changed, true)) {
            $this->log($model, $model->archived_at ? 'archived' : 'restored', $model->archived_at ? 'Archived the' : 'Restored the');
        } elseif ($changed !== []) {
            $this->log($model, 'changed', 'Changed the', ' ('.str_replace('_', ' ', implode(', ', $changed)).')');
        }
    }

    public function deleted(Model $model): void
    {
        $this->log($model, 'removed', 'Removed the');
    }

    private function log(Model $model, string $action, string $words, string $detail = ''): void
    {
        $team = $model instanceof TaskStatus ? $model->taskList?->team_id : $model->team_id;

        // A person's private Inbox is theirs, not part of how the business is set up.
        if ($team !== null && ! ($model instanceof TaskList && $model->is_inbox)) {
            AuditLog::record($team, $action, $words.' '.self::NAMES[$model::class].' "'.$model->name.'"'.$detail);
        }
    }
}
