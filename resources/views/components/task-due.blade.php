@props(['task'])
@if ($task->due_date)
    @php $late = $task->status !== 'done' && $task->due_date->isPast() && ! $task->due_date->isToday(); @endphp
    <span {{ $attributes->class(['num', 'overdue' => $late]) }}>{{ $task->due_date->isToday() ? 'Today' : ($task->due_date->isTomorrow() ? 'Tomorrow' : $task->due_date->format('M j')) }}</span>
@endif
