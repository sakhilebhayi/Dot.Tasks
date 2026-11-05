<details class="repeat" @if($task->isRecurring()) open @endif>
    <summary class="section-title" style="font-size:14px">{{ $task->isRecurring() ? 'Repeats' : 'Repeat this task' }}@if($task->isRecurring() && $task->recurrence_limit) <span class="num" style="color:var(--muted);font-weight:400">{{ $task->recurrence_count }} of {{ $task->recurrence_limit }}</span>@endif</summary>
    @can('update', $task)
        <form wire:submit="save">
            <div class="row-2">
                <div class="field">
                    <label for="repeat-every">Every</label>
                    <input class="input" id="repeat-every" type="number" min="1" max="365" wire:model="every">
                    @error('every')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="repeat-kind">&nbsp;<span class="sr">Period</span></label>
                    <select class="select" id="repeat-kind" wire:model.live="kind">
                        @foreach(\App\Services\RecurrenceEditor::KINDS as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if($kind === 'weekly')
                <fieldset class="field" style="border:0;padding:0;margin-bottom:12px">
                    <legend class="label">On</legend>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        @foreach(\App\Livewire\Tasks\TaskRepeat::WEEKDAYS as $value => $name)
                            <label class="pick-line" style="padding:0"><input type="checkbox" value="{{ $value }}" wire:model="days"> {{ $name }}</label>
                        @endforeach
                    </div>
                </fieldset>
            @endif
            <div class="row-2">
                <div class="field">
                    <label for="repeat-until">Ends on</label>
                    <input class="input" id="repeat-until" type="date" wire:model="until">
                    @error('until')<p class="error">{{ $message }}</p>@enderror
                </div>
                <div class="field">
                    <label for="repeat-limit">Or after this many times</label>
                    <input class="input" id="repeat-limit" type="number" min="1" wire:model="limit">
                    @error('limit')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="row-2">
                <div class="field">
                    <label for="repeat-basis">Counted from</label>
                    <select class="select" id="repeat-basis" wire:model="basis">
                        <option value="due_date">The due date</option>
                        <option value="completion">The day it is finished</option>
                    </select>
                </div>
                <div class="field">
                    <label for="repeat-missed">If it falls behind</label>
                    <select class="select" id="repeat-missed" wire:model="missed">
                        <option value="skip">Pick up from today</option>
                        <option value="keep">Keep every occurrence</option>
                    </select>
                </div>
            </div>
            <div class="page-actions">
                <button type="submit" class="btn small">{{ $task->isRecurring() ? 'Save for the series' : 'Repeat it' }}</button>
                @if($task->isRecurring())
                    <button type="button" class="btn small quiet" style="opacity:1" wire:click="useForSeries">Use these details for the series</button>
                    <button type="button" class="btn small quiet" style="opacity:1" wire:click="stop">Stop repeating</button>
                @endif
            </div>
            @if($notice !== '')<p role="status" style="margin-top:6px;color:var(--muted)">{{ $notice }}</p>@endif
        </form>
    @else
        <p style="color:var(--muted)">Every {{ $task->recurrence_interval }} {{ $task->recurrence_type }}</p>
    @endcan
</details>
