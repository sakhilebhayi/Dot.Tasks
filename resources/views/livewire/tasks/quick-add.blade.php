<div>
    @if($addedId && ! $open)
        <div class="flash" role="status" wire:key="added-{{ $addedId }}" x-data x-init="setTimeout(() => $wire.set('addedId', null), 6000)">
            Added to {{ $addedTo }}.
            <button type="button" class="link" style="color:inherit" wire:click="$dispatch('open-task', { taskId: {{ $addedId }} }); $set('addedId', null)">Open it</button>
        </div>
    @endif
    @if($open)
        <div class="veil over-sheet" wire:click="close"></div>
        <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="quick-add-heading" x-data x-on:keydown.escape.window="$wire.close()">
            <h2 id="quick-add-heading">New task</h2>
            <form wire:submit="save">
                <div class="field">
                    <label class="sr" for="quick-add-sentence">What needs doing</label>
                    <input class="input" id="quick-add-sentence" wire:model.live.debounce.150ms="sentence" autocomplete="off" autofocus x-init="$nextTick(() => $el.focus())"
                           placeholder="Prepare the monthly report for Friday and assign it to James">
                    @error('sentence')<p class="error">{{ $message }}</p>@enderror
                </div>
                @if(trim($sentence) !== '')
                    <div class="understood" aria-live="polite">
                        <p><b>{{ $this->read['title'] ?: 'Say what needs doing' }}</b></p>
                        <div class="page-actions" style="margin-top:6px">
                            <span class="chip">{{ $this->read['due_date'] ? 'Due '.\Illuminate\Support\Carbon::parse($this->read['due_date'])->format('D, M j') : 'No due date' }}</span>
                            <span class="chip">{{ $this->read['assignee_name'] ?? 'Unassigned' }}</span>
                            @if($this->read['priority'])<span class="chip">{{ ucfirst($this->read['priority']) }}</span>@endif
                        </div>
                        @if($this->read['unknown_person'])
                            <p class="blocked-line">Nobody on this team is called {{ $this->read['unknown_person'] }}, so it stays unassigned.</p>
                        @endif
                    </div>
                @endif
                <div class="field">
                    <label for="quick-add-list">Goes on</label>
                    <select class="select" id="quick-add-list" wire:model="listId">
                        @foreach($this->lists as $list)
                            <option value="{{ $list->id }}">{{ $list->is_inbox ? 'Inbox (only you)' : $list->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dialog-actions">
                    <button type="button" class="btn quiet" wire:click="close">Cancel</button>
                    <button type="submit" class="btn primary">Create task</button>
                </div>
            </form>
        </div>
    @endif
</div>
