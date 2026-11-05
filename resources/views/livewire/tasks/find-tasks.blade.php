<div>
    @if($open)
        <div class="veil over-sheet" wire:click="close"></div>
        <div class="dialog" role="dialog" aria-modal="true" aria-label="Find a task" x-data x-on:keydown.escape.window="$wire.close()">
            <label class="sr" for="find-input">Find a task</label>
            <input class="input" id="find-input" type="search" wire:model.live.debounce.200ms="query" placeholder="Find a task" autocomplete="off" x-init="$nextTick(() => $el.focus())">
            @if(mb_strlen(trim($query)) >= 2)
                @if($this->results->isEmpty())
                    <p style="padding:16px 4px 4px;color:var(--muted)">Nothing matches that.</p>
                @else
                    <div style="margin-top:8px">
                        @foreach($this->results as $task)
                            <button type="button" @class(['row', 'is-done' => $task->status === 'done']) style="width:100%;border-width:0 0 1px;background:none;text-align:left" wire:key="found-{{ $task->id }}"
                                    wire:click="$dispatch('open-task', { taskId: {{ $task->id }} }); $set('open', false)">
                                <span class="row-main"><b>{{ $task->title }}</b><span>{{ $task->taskList->is_inbox ?? false ? 'Inbox' : $task->taskList->name }}</span></span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
