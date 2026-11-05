<div>
    @if($showForm)
        <div class="veil" wire:click="cancel"></div>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="new-task-heading">
            <h2 id="new-task-heading">New task</h2>
            <form wire:submit="save">
                <div class="field">
                    <input class="input" aria-label="Task title" wire:model="title" type="text" placeholder="Task title" autofocus>
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <textarea class="textarea" aria-label="Description" wire:model="description" rows="2" placeholder="Description (optional)"></textarea>
                </div>
                <div class="row-2">
                    <div class="field">
                        <label for="livewire-tasks-create-task-priority">Priority</label>
                        <select class="select" id="livewire-tasks-create-task-priority" wire:model="priority">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="livewire-tasks-create-task-duedate">Due date</label>
                        <input class="input" id="livewire-tasks-create-task-duedate" wire:model="dueDate" type="date">
                    </div>
                </div>
                <div class="field">
                    <label for="new-task-assignee">Assignee</label>
                    <select class="select" id="new-task-assignee" wire:model="assigneeId">
                        <option value="">Unassigned</option>
                        @foreach($this->teamMembers as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                    @error('assigneeId')<p class="error">{{ $message }}</p>@enderror
                </div>
                @if($this->teamLabels->isNotEmpty())
                    <fieldset class="field" style="border:0;padding:0;margin:0 0 14px">
                        <legend class="label" style="padding:0;margin-bottom:6px">Labels</legend>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            @foreach($this->teamLabels as $label)
                                <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                                    <input type="checkbox" wire:model="labelIds" value="{{ $label->id }}">
                                    <x-label-chip :label="$label" />
                                </label>
                            @endforeach
                        </div>
                        @error('labelIds')<p class="error">{{ $message }}</p>@enderror
                    </fieldset>
                @endif
                <div class="row-2">
                    <div class="field">
                        <label for="livewire-tasks-create-task-estimatedminutes">Estimate (minutes)</label>
                        <input class="input" id="livewire-tasks-create-task-estimatedminutes" wire:model="estimatedMinutes" type="number" min="1" placeholder="e.g. 30">
                    </div>
                    <div class="field">
                        <label for="livewire-tasks-create-task-recurrencetype">Repeat</label>
                        <select class="select" id="livewire-tasks-create-task-recurrencetype" wire:model.live="recurrenceType">
                            <option value="">Doesn't repeat</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="custom_days">Every N days</option>
                        </select>
                    </div>
                </div>
                @if($recurrenceType)
                    <div class="row-2">
                        <div class="field">
                            <label for="livewire-tasks-create-task-recurrenceinterval">Every</label>
                            <input class="input" id="livewire-tasks-create-task-recurrenceinterval" wire:model="recurrenceInterval" type="number" min="1" max="365">
                            @error('recurrenceInterval')<p class="error">{{ $message }}</p>@enderror
                        </div>
                        <div class="field">
                            <label for="livewire-tasks-create-task-recurrenceanchor">Based on</label>
                            <select class="select" id="livewire-tasks-create-task-recurrenceanchor" wire:model="recurrenceAnchor">
                                <option value="due_date">Due date</option>
                                <option value="completion">Completion date</option>
                            </select>
                        </div>
                    </div>
                @endif
                <div class="dialog-actions">
                    <button type="button" class="btn quiet" wire:click="cancel">Cancel</button>
                    <button type="submit" class="btn primary">Create task</button>
                </div>
            </form>
        </div>
    @endif
</div>
