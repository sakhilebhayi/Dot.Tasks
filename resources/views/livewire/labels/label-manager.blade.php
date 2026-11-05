<div>
    @if($this->canManage)
        <form wire:submit="create" class="panel" style="margin-bottom:12px">
            <label class="label" for="new-label-name">New label</label>
            <div class="page-actions" style="margin-top:6px">
                <input class="input" style="flex:1 1 220px" id="new-label-name" wire:model="name" type="text" maxlength="{{ \App\Models\Label::NAME_MAX }}" placeholder="e.g. Customer escalation">
                <x-label-palette model="color" :selected="$color" />
                <button type="submit" class="btn">Add label</button>
            </div>
            @error('name')<p class="error">{{ $message }}</p>@enderror
            @error('color')<p class="error">{{ $message }}</p>@enderror
        </form>
    @endif

    @if($this->labels->isEmpty())
        <div class="empty">
            <p>No labels yet</p>
            <p>
                @if($this->canManage)
                    Add the first one above, then put it on tasks from the task drawer.
                @else
                    A team admin has not created any yet.
                @endif
            </p>
        </div>
    @else
        <div class="rows">
            @foreach($this->labels as $label)
                <div class="row" style="flex-wrap:wrap" wire:key="label-{{ $label->id }}">
                    @if($editingId === $label->id)
                        <form wire:submit="saveEdit" class="page-actions" style="width:100%">
                            <input class="input" style="flex:1 1 200px" wire:model="editName" type="text" maxlength="{{ \App\Models\Label::NAME_MAX }}" aria-label="Label name">
                            <x-label-palette model="editColor" :selected="$editColor" />
                            <button type="submit" class="btn small primary">Save</button>
                            <button type="button" class="btn small quiet" wire:click="cancelEdit">Cancel</button>
                        </form>
                        <div>
                            @error('editName')<p class="error">{{ $message }}</p>@enderror
                            @error('editColor')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @else
                        <span class="swatch" style="background: {{ $label->color }}"></span>
                        <span class="row-main"><b>{{ $label->name }}</b></span>
                        <span class="row-end num" title="Tasks carrying this label">{{ $label->tasks_count }} {{ $label->tasks_count === 1 ? 'task' : 'tasks' }}</span>
                        @if($this->canManage)
                            <button type="button" class="btn small" wire:click="startEdit({{ $label->id }})">Edit</button>
                            <button type="button" class="btn small danger"
                                    wire:click="delete({{ $label->id }})"
                                    wire:confirm="Delete the label &quot;{{ $label->name }}&quot;? It comes off {{ $label->tasks_count }} {{ $label->tasks_count === 1 ? 'task' : 'tasks' }}."
                                   >Delete</button>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
