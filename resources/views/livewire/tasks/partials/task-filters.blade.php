<div class="filters" x-data="{ more: false }">
    <x-dot.filter-search name="search" wire:model.live.debounce.300ms="search" placeholder="Search tasks..." label="Search tasks" />
    <button type="button" class="btn filter-toggle" x-on:click="more = ! more" x-bind:aria-expanded="more">Filters</button>
    <div class="filter-rest" x-bind:class="more && 'open'">
    <select class="select bare" wire:model.live="assignee" aria-label="Assignee">
        <option value="">Anyone</option>
        <option value="me">Assigned to me</option>
        <option value="none">Unassigned</option>
        @foreach($this->members as $member)
            @if($member->id !== auth()->id())
                <option value="{{ $member->id }}">{{ $member->name }}</option>
            @endif
        @endforeach
    </select>
    <select class="select bare" wire:model.live="priority" aria-label="Priority">
        <option value="">Any priority</option>
        @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
            <option value="{{ $p }}">{{ ucfirst($p) }}</option>
        @endforeach
    </select>
    <select class="select bare" wire:model.live="due" aria-label="Due">
        <option value="">Any due date</option>
        @foreach(\App\Livewire\Tasks\TaskBoard::DUE_STATES as $value => $text)
            <option value="{{ $value }}">{{ $text }}</option>
        @endforeach
    </select>
    @if($this->labels->isNotEmpty())
        <select class="select bare" wire:model.live="labelId" aria-label="Label">
            <option value="">Any label</option>
            @foreach($this->labels as $label)
                <option value="{{ $label->id }}">{{ $label->name }}</option>
            @endforeach
        </select>
    @endif
    @if($this->customFields->isNotEmpty())
        <select class="select bare" wire:model.live="fieldId" aria-label="Custom field">
            <option value="">Any field</option>
            @foreach($this->customFields as $custom)
                <option value="{{ $custom->id }}">{{ $custom->name }}</option>
            @endforeach
        </select>
        @if($fieldId !== '')
            <input class="input" style="width:140px" wire:model.live.debounce.300ms="fieldValue" aria-label="Value to look for" placeholder="Is exactly">
        @endif
    @endif
    @if($extras === 'board')
        <select class="select bare" wire:model.live="lanes" aria-label="Group into lanes">
            <option value="">No lanes</option>
            <option value="assignee">Lanes by person</option>
            <option value="priority">Lanes by priority</option>
        </select>
    @endif
    @if($extras === 'calendar')
        <select class="select bare" wire:model.live="state" aria-label="Status">
            <option value="">Any status</option>
            @foreach(\App\Livewire\Tasks\TaskBoard::COLUMNS as $value => $name)
                <option value="{{ $value }}">{{ $name }}</option>
            @endforeach
        </select>
    @endif
    @if($this->filtersActive)
        <button type="button" wire:click="clearFilters" class="link">Clear filters</button>
    @endif
    </div>
    @can('create', [\App\Models\Task::class, $taskList])
        <button class="btn primary" style="margin-left:auto" wire:click="$dispatch('open-create-task', { status: 'todo' })">
            <x-icon name="plus" /> Add task
        </button>
    @endcan
</div>
<div class="filters saved-views" x-data="{ naming: false, name: '' }">
    @foreach($this->savedViews as $saved)
        <span class="chip" wire:key="saved-{{ $saved->id }}">
            <a href="{{ route('task-lists.show', [$taskList->id] + $saved->settings) }}">{{ $saved->name }}</a>
            <button type="button" class="chip-x" wire:click="deleteView({{ $saved->id }})" aria-label="Delete the view {{ $saved->name }}"><x-icon name="close" /></button>
        </span>
    @endforeach
    <button type="button" class="link" x-show="! naming" x-on:click="naming = true; $nextTick(() => $refs.name.focus())">Save this view</button>
    <form x-show="naming" x-cloak x-on:submit.prevent="$wire.saveView(name); name = ''; naming = false">
        <input class="input" x-ref="name" x-model="name" maxlength="60" placeholder="Name this view" aria-label="Name this view" x-on:keydown.escape="naming = false" style="min-height:28px;width:180px">
    </form>
    @error('viewName')<span class="error">{{ $message }}</span>@enderror
</div>
