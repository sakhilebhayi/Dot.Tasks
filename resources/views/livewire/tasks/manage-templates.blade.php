<div>
    @if($notice !== '')<p role="status" class="alert quiet-alert">{{ $notice }}</p>@endif
    <div class="filters">
        <label class="inline-field">Make tasks on
            <select class="select bare" wire:model="board">
                @foreach($this->boards as $option)
                    <option value="{{ $option->id }}">{{ $option->is_inbox ? 'Inbox' : $option->name }}</option>
                @endforeach
            </select>
        </label>
        @if($this->canManage)
            <button type="button" class="btn primary" style="margin-left:auto" wire:click="start"><x-icon name="plus" /> New template</button>
        @endif
    </div>

    @if($editing !== null)
        <form wire:submit="save" class="panel" style="margin-bottom:12px">
            <div class="row-2">
                <div class="field">
                    <label for="template-name">Template name</label>
                    <input class="input" id="template-name" wire:model="name" maxlength="120">
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="template-title">Task title</label>
                    <input class="input" id="template-title" wire:model="title" maxlength="255">
                    @error('title')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="field">
                <label for="template-description">Description</label>
                <textarea class="textarea" id="template-description" rows="2" wire:model="description"></textarea>
            </div>
            <div class="row-2">
                <div class="field">
                    <label for="template-priority">Priority</label>
                    <select class="select" id="template-priority" wire:model="priority">
                        @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                            <option value="{{ $p }}">{{ ucfirst($p) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="field">
                    <label for="template-due">Due after this many days</label>
                    <input class="input" id="template-due" type="number" min="0" wire:model="dueIn">
                    @error('dueIn')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="field">
                <label for="template-assignee">Given to</label>
                <select class="select" id="template-assignee" wire:model="assignee">
                    <option value="">Nobody yet</option>
                    @foreach($this->members as $member)
                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                    @endforeach
                </select>
                @error('assignee')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="row-2">
                <div class="field">
                    <label for="template-subtasks">Subtasks, one a line</label>
                    <textarea class="textarea" id="template-subtasks" rows="5" wire:model="subtasks"></textarea>
                </div>
                <div class="field">
                    <label for="template-checklist">Checklist, one a line</label>
                    <textarea class="textarea" id="template-checklist" rows="5" wire:model="checklist"></textarea>
                </div>
            </div>
            <div class="dialog-actions">
                <button type="button" class="btn quiet" wire:click="cancel">Cancel</button>
                <button type="submit" class="btn primary">Save template</button>
            </div>
        </form>
    @endif

    @if($this->templates->isEmpty())
        <div class="empty">
            <p>No templates yet.</p>
            @if($this->canManage)
                <button type="button" class="btn" style="margin-top:8px" wire:click="addStarters">Add eight to start from</button>
            @endif
        </div>
    @else
        <div class="rows" @if($this->canManage) x-data="stepList('placeTemplate')" @endif>
            @foreach($this->templates as $template)
                <div class="row" data-task="{{ $template->id }}" wire:key="template-{{ $template->id }}">
                    @if($this->canManage)
                        <button type="button" class="grip" aria-label="Move {{ $template->name }}, use the up and down arrows" x-on:keydown="keys($event, {{ $template->id }})"><x-icon name="grip" /></button>
                    @endif
                    <div class="row-main">
                        <b>{{ $template->name }}</b>
                        <span>{{ count($template->subtasks ?? []) }} subtasks, {{ count($template->checklist ?? []) }} checklist lines{{ $template->due_in_days !== null ? ', due after '.$template->due_in_days.' days' : '' }}</span>
                    </div>
                    <div class="row-end">
                        @if($this->boards->isNotEmpty())
                            <button type="button" class="btn small primary" wire:click="use({{ $template->id }})" aria-label="Use {{ $template->name }}">Use</button>
                        @endif
                        @if($this->canManage)
                            <button type="button" class="btn small" wire:click="start({{ $template->id }})" aria-label="Edit {{ $template->name }}">Edit</button>
                            <button type="button" class="btn small danger" wire:click="remove({{ $template->id }})" wire:confirm="Delete the template {{ $template->name }}? Tasks made from it stay." aria-label="Delete {{ $template->name }}">Delete</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
