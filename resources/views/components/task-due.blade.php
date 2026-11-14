@props(['task'])
@if ($task->due_date)
    <span {{ $attributes->class(['num', 'overdue' => $task->isOverdue(), 'due-today' => $task->status !== 'done' && $task->isDueToday()]) }}>{{ $task->isDueToday() ? 'Today' : ($task->isDueTomorrow() ? 'Tomorrow' : $task->due_date->format('M j')) }}</span>
@endif
