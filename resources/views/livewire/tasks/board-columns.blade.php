<div>
    <button type="button" class="btn quiet" wire:click="$set('open', true)">Columns</button>
    @if($open)
        <div class="veil over-sheet" wire:click="$set('open', false)"></div>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="columns-heading" x-data x-on:keydown.escape.window="$wire.set('open', false)">
            <h2 id="columns-heading">Columns on {{ $taskList->name }}</h2>
            <p style="color:var(--muted);margin-bottom:12px">Each column means one of four things, and that is what reminders, finishing and reports go by.</p>
            @error('column')<p role="alert" class="alert">{{ $message }}</p>@enderror
            <div x-data="stepList('placeColumn')" wire:key="columns-{{ $edits }}">
                @foreach($this->columns as $column)
                    <div class="step" data-task="{{ $column->id }}" wire:key="column-{{ $column->id }}" style="min-height:40px">
                        <button type="button" class="grip" aria-label="Move {{ $column->name }}, use the up and down arrows" x-on:keydown="keys($event, {{ $column->id }})"><x-icon name="grip" /></button>
                        <input class="input" style="flex:1;min-height:30px;padding:4px 8px" aria-label="Name of {{ $column->name }}" value="{{ $column->name }}" maxlength="40" wire:change="rename({{ $column->id }}, $event.target.value)">
                        <select class="select bare" aria-label="What {{ $column->name }} means" wire:change="recategorize({{ $column->id }}, $event.target.value)">
                            @foreach(\App\Models\TaskStatus::CATEGORIES as $value => $name)
                                <option value="{{ $value }}" @selected($column->category === $value)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <button type="button" class="btn small quiet" style="opacity:1" aria-label="Remove {{ $column->name }}" wire:click="remove({{ $column->id }})" wire:confirm="Remove {{ $column->name }}? Its cards move to another column.">Remove</button>
                    </div>
                @endforeach
            </div>
            <form wire:submit="add" class="step-add" style="margin-top:12px">
                <input class="input" aria-label="New column" wire:model="newName" maxlength="40" placeholder="New column" autocomplete="off">
                <select class="select bare" aria-label="What the new column means" wire:model="newCategory">
                    @foreach(\App\Models\TaskStatus::CATEGORIES as $value => $name)
                        <option value="{{ $value }}">{{ $name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn small">Add</button>
            </form>
            <div class="dialog-actions">
                <button type="button" class="btn primary" wire:click="$set('open', false)">Done</button>
            </div>
        </div>
    @endif
</div>
