<div>
    <h2 class="section-title">Custom fields</h2>
    <p style="color:var(--muted);margin-bottom:12px">Extra details on every task of this team, such as a cost centre or a supplier.</p>
    @if($this->canManage)
        <form wire:submit="add" class="panel" style="margin-bottom:12px">
            <div class="row-2">
                <div class="field">
                    <label for="field-name">Name</label>
                    <input class="input" id="field-name" wire:model="name" maxlength="60" placeholder="Cost centre">
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="field-type">Kind</label>
                    <select class="select" id="field-type" wire:model.live="type">
                        @foreach(\App\Models\TaskField::TYPES as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if($type === 'select')
                <div class="field">
                    <label for="field-choices">Choices, one a line</label>
                    <textarea class="textarea" id="field-choices" rows="3" wire:model="choices"></textarea>
                    @error('choices')<p class="error">{{ $message }}</p>@enderror
                </div>
            @endif
            <button type="submit" class="btn primary">Add field</button>
        </form>
    @endif
    @if($this->fields->isEmpty())
        <div class="empty">No custom fields yet.</div>
    @else
        <div class="rows">
            @foreach($this->fields as $field)
                <div class="row" wire:key="field-{{ $field->id }}">
                    <div class="row-main">
                        <b>{{ $field->name }}</b>
                        <span>{{ \App\Models\TaskField::TYPES[$field->type] }}{{ $field->options ? ': '.implode(', ', $field->options) : '' }}</span>
                    </div>
                    @if($this->canManage)
                        <button type="button" class="btn small danger" wire:click="remove({{ $field->id }})" wire:confirm="Delete {{ $field->name }}, and what it holds on every task?" aria-label="Delete {{ $field->name }}">Delete</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
