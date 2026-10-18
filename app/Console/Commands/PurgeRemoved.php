<?php

namespace App\Console\Commands;

use App\Services\RemovedTaskPurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PurgeRemoved extends Command
{
    protected $signature = 'tasks:purge-removed {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Permanently delete tasks deleted more than REMOVED_RETENTION_DAYS ago, with their subtasks';

    public function handle(RemovedTaskPurger $purger): int
    {
        $days = (int) config('tasks.removed_retention_days');
        if ($days < 1) {
            $this->error('REMOVED_RETENTION_DAYS must be at least 1; refusing to purge.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);
        ['purged' => $purged, 'kept' => $kept] = $purger->purge($cutoff, $dryRun);

        Log::info('tasks.purge-removed', [
            'dry_run' => $dryRun,
            'retention_days' => $days,
            'cutoff' => $cutoff->toIso8601String(),
            'purged' => $purged,
            'kept' => $kept,
        ]);

        $parts = array_values(array_filter([
            $purged['tasks'] > 0 ? $purged['tasks'].' '.Str::plural('task', $purged['tasks']) : null,
            $purged['comments'] > 0 ? $purged['comments'].' '.Str::plural('comment', $purged['comments']) : null,
        ]));

        $line = match (true) {
            $parts === [] => "Nothing to purge: no task has been deleted for more than {$days} days.",
            $dryRun => 'Dry run, nothing deleted. Would purge '.implode(' and ', $parts)." (deleted before {$cutoff->toDateTimeString()}).",
            default => 'Purged '.implode(' and ', $parts)." (deleted before {$cutoff->toDateTimeString()}).",
        };

        if ($kept['live_subtasks'] > 0 || $kept['series_heads'] > 0) {
            $line .= " Kept {$kept['live_subtasks']} with live subtasks and {$kept['series_heads']} still heading a live recurring series.";
        }

        $this->line($line);

        return self::SUCCESS;
    }
}
