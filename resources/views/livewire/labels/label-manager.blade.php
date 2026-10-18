<div>
    @if($this->canManage)
        <form wire:submit="create" class="dot-card" style="padding:1rem 1.25rem;margin-bottom:1.25rem;">
            <label for="new-label-name" style="display:block;font-size:0.7rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;margin-bottom:0.5rem;">New label</label>
            <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                <input id="new-label-name" wire:model="name" type="text" maxlength="{{ \App\Models\Label::NAME_MAX }}" placeholder="e.g. Customer escalation" class="dot-input" style="flex:1;min-width:14rem;">
                <x-label-palette model="color" :selected="$color" />
                <button type="submit" class="dot-btn dot-btn-primary">Add label</button>
            </div>
            @error('name')<p style="margin:0.5rem 0 0;font-size:0.75rem;color:#f87171;">{{ $message }}</p>@enderror
            @error('color')<p style="margin:0.5rem 0 0;font-size:0.75rem;color:#f87171;">{{ $message }}</p>@enderror
        </form>
    @endif

    @if($this->labels->isEmpty())
        <div class="dot-card" style="padding:3rem 2rem;text-align:center;">
            <p style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 0.4rem;">No labels yet</p>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">
                @if($this->canManage)
                    Add the first one above, then put it on tasks from the task drawer.
                @else
                    A team admin has not created any yet.
                @endif
            </p>
        </div>
    @else
        <div class="dot-card" style="overflow:hidden;">
            @foreach($this->labels as $label)
                <div wire:key="label-{{ $label->id }}" style="display:flex;align-items:center;gap:0.9rem;padding:0.8rem 1.25rem;{{ ! $loop->last ? 'border-bottom:1px solid rgba(255,255,255,0.06);' : '' }}">
                    @if($editingId === $label->id)
                        <form wire:submit="saveEdit" style="display:flex;align-items:center;gap:0.75rem;flex:1;flex-wrap:wrap;">
                            <input wire:model="editName" type="text" maxlength="{{ \App\Models\Label::NAME_MAX }}" aria-label="Label name" class="dot-input" style="flex:1;min-width:12rem;">
                            <x-label-palette model="editColor" :selected="$editColor" />
                            <button type="submit" class="dot-btn dot-btn-primary">Save</button>
                            <button type="button" wire:click="cancelEdit" class="dot-btn dot-btn-ghost">Cancel</button>
                        </form>
                        <div style="flex-basis:100%;">
                            @error('editName')<p style="margin:0;font-size:0.75rem;color:#f87171;">{{ $message }}</p>@enderror
                            @error('editColor')<p style="margin:0;font-size:0.75rem;color:#f87171;">{{ $message }}</p>@enderror
                        </div>
                    @else
                        <span style="width:12px;height:12px;border-radius:50%;background:{{ $label->color }};flex-shrink:0;"></span>
                        <span style="flex:1;min-width:0;font-size:0.9rem;font-weight:600;color:#f4f4f5;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $label->name }}</span>
                        <span class="metric-val" style="font-size:0.75rem;color:#71717a;" title="Tasks carrying this label">{{ $label->tasks_count }} {{ $label->tasks_count === 1 ? 'task' : 'tasks' }}</span>
                        @if($this->canManage)
                            <button type="button" wire:click="startEdit({{ $label->id }})" class="dot-btn dot-btn-ghost" style="padding:4px 10px;">Edit</button>
                            <button type="button"
                                    wire:click="delete({{ $label->id }})"
                                    wire:confirm="Delete the label &quot;{{ $label->name }}&quot;? It comes off {{ $label->tasks_count }} {{ $label->tasks_count === 1 ? 'task' : 'tasks' }}."
                                    class="dot-btn dot-btn-ghost" style="padding:4px 10px;color:#f87171;">Delete</button>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
