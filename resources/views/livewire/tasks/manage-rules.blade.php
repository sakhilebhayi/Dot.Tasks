<div>
    @if($this->canManage && $editing === null)
        <div class="filters" style="justify-content:flex-end">
            <button type="button" class="btn primary" wire:click="start"><x-icon name="plus" /> New rule</button>
        </div>
    @endif

    @if($editing !== null)
        <form wire:submit="save" class="panel" style="margin-bottom:16px">
            <div class="row-2">
                <div class="field">
                    <label for="rule-name">Name</label>
                    <input class="input" id="rule-name" wire:model.live.debounce.300ms="name" maxlength="120">
                </div>
                <div class="field">
                    <label for="rule-board">Applies to</label>
                    <select class="select" id="rule-board" wire:model.live="board">
                        <option value="">Every board</option>
                        @foreach($this->boards as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="rule-trigger">When</label>
                <select class="select" id="rule-trigger" wire:model.live="trigger">
                    @foreach(\App\Services\AutomationCatalog::TRIGGERS as $value => $name)
                        <option value="{{ $value }}">{{ ucfirst($name) }}</option>
                    @endforeach
                </select>
            </div>

            <fieldset class="field rule-rows">
                <legend class="label">Only if
                    @if(count($conditions) > 1)
                        <select class="select bare" wire:model.live="match" aria-label="All or any of these">
                            <option value="all">all of these hold</option>
                            <option value="any">any of these holds</option>
                        </select>
                    @endif
                </legend>
                @foreach($conditions as $i => $row)
                    <div class="rule-row" wire:key="condition-{{ $i }}">
                        <select class="select bare" aria-label="Condition {{ $i + 1 }}: what to look at" wire:model.live="conditions.{{ $i }}.field">
                            @foreach(\App\Services\AutomationCatalog::FIELDS as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                            @foreach($this->customFields as $custom)
                                <option value="f{{ $custom->id }}">{{ $custom->name }}</option>
                            @endforeach
                        </select>
                        <select class="select bare" aria-label="Condition {{ $i + 1 }}: how to compare" wire:model.live="conditions.{{ $i }}.op">
                            @foreach(\App\Services\AutomationCatalog::OPERATORS as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @unless(in_array($row['op'], ['empty', 'filled'], true))
                            @switch($row['field'])
                                @case('priority')
                                    <select class="select bare" aria-label="Condition {{ $i + 1 }}: value" wire:model.live="conditions.{{ $i }}.value">
                                        @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach
                                    </select>
                                    @break
                                @case('status')
                                    <select class="select bare" aria-label="Condition {{ $i + 1 }}: value" wire:model.live="conditions.{{ $i }}.value">
                                        <option value="">Choose</option>
                                        @foreach(\App\Models\TaskStatus::CATEGORIES as $value => $name)<option value="{{ $value }}">{{ $name }}</option>@endforeach
                                    </select>
                                    @break
                                @case('assignee')
                                    <select class="select bare" aria-label="Condition {{ $i + 1 }}: value" wire:model.live="conditions.{{ $i }}.value">
                                        <option value="">Choose</option>
                                        @foreach($this->members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                                    </select>
                                    @break
                                @case('label')
                                    <select class="select bare" aria-label="Condition {{ $i + 1 }}: value" wire:model.live="conditions.{{ $i }}.value">
                                        <option value="">Choose</option>
                                        @foreach($this->labels as $label)<option value="{{ $label->id }}">{{ $label->name }}</option>@endforeach
                                    </select>
                                    @break
                                @default
                                    <input class="input" style="min-height:30px" aria-label="Condition {{ $i + 1 }}: value" wire:model.live.debounce.300ms="conditions.{{ $i }}.value">
                            @endswitch
                        @endunless
                        <button type="button" class="icon-btn" wire:click="removeCondition({{ $i }})" aria-label="Remove condition {{ $i + 1 }}"><x-icon name="close" /></button>
                    </div>
                @endforeach
                <button type="button" class="link" wire:click="addCondition">Add a condition</button>
            </fieldset>

            <div class="row-2">
                <div class="field">
                    <label for="rule-delay">Wait this many minutes first</label>
                    <input class="input" id="rule-delay" type="number" min="0" wire:model.live.debounce.300ms="delay">
                </div>
                <div class="field">
                    <label class="pick-line" style="padding:26px 0 0"><input type="checkbox" wire:model.live="approval"> An admin approves each run</label>
                </div>
            </div>

            <fieldset class="field rule-rows">
                <legend class="label">Then</legend>
                @foreach($actions as $i => $row)
                    <div class="rule-row" wire:key="action-{{ $i }}">
                        <select class="select bare" aria-label="Action {{ $i + 1 }}" wire:model.live="actions.{{ $i }}.type">
                            @foreach(\App\Services\AutomationCatalog::ACTIONS as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @switch($row['type'])
                            @case('assign')
                            @case('approval')
                                <select class="select bare" aria-label="Action {{ $i + 1 }}: who" wire:model.live="actions.{{ $i }}.value">
                                    <option value="">Choose</option>
                                    @foreach($this->members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                                </select>
                                @break
                            @case('notify')
                                <select class="select bare" aria-label="Action {{ $i + 1 }}: who" wire:model.live="actions.{{ $i }}.value">
                                    <option value="assignee">The person on the task</option>
                                    <option value="creator">The person who made it</option>
                                    @foreach($this->members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                                </select>
                                @break
                            @case('priority')
                                <select class="select bare" aria-label="Action {{ $i + 1 }}: priority" wire:model.live="actions.{{ $i }}.value">
                                    <option value="">Choose</option>
                                    @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)<option value="{{ $p }}">{{ ucfirst($p) }}</option>@endforeach
                                </select>
                                @break
                            @case('move')
                                <select class="select bare" aria-label="Action {{ $i + 1 }}: where" wire:model.live="actions.{{ $i }}.value">
                                    <option value="">Choose</option>
                                    @foreach(\App\Services\AutomationCatalog::MOVES as $value => $name)<option value="{{ $value }}">{{ ucfirst($name) }}</option>@endforeach
                                </select>
                                @break
                            @case('label')
                                <select class="select bare" aria-label="Action {{ $i + 1 }}: label" wire:model.live="actions.{{ $i }}.value">
                                    <option value="">Choose</option>
                                    @foreach($this->labels as $label)<option value="{{ $label->id }}">{{ $label->name }}</option>@endforeach
                                </select>
                                @break
                            @default
                                <input class="input" style="min-height:30px" aria-label="Action {{ $i + 1 }}: value" wire:model.live.debounce.300ms="actions.{{ $i }}.value">
                        @endswitch
                        <button type="button" class="icon-btn" wire:click="removeAction({{ $i }})" aria-label="Remove action {{ $i + 1 }}"><x-icon name="close" /></button>
                    </div>
                @endforeach
                <button type="button" class="link" wire:click="addAction">Add an action</button>
            </fieldset>

            <p class="understood" aria-live="polite" id="rule-preview">{{ $this->preview }}</p>
            @error('rule')<p role="alert" class="alert">{{ $message }}</p>@enderror
            <div class="dialog-actions">
                <button type="button" class="btn quiet" wire:click="cancel">Cancel</button>
                <button type="submit" class="btn primary">Save rule</button>
            </div>
        </form>
    @endif

    @if($this->rules->isEmpty())
        <div class="empty">No rules yet.</div>
    @else
        <div class="rows">
            @foreach($this->rules as $rule)
                @php $count = $rule->enabled ? null : $automations->wouldTake($rule)->count(); @endphp
                <div class="row" style="flex-wrap:wrap" wire:key="rule-{{ $rule->id }}">
                    <div class="row-main" style="min-width:240px">
                        <b>{{ $rule->name }} @if($rule->enabled)<span class="chip go">On</span>@else<span class="chip">Off</span>@endif</b>
                        <span style="white-space:normal">{{ $catalog->describe($rule) }}</span>
                        <span>Acts as {{ $rule->author?->name ?? 'nobody: its author has gone' }}@if($count !== null). {{ $count }} open {{ \Illuminate\Support\Str::plural('task', $count) }} fit it today.@endif</span>
                    </div>
                    @if($this->canManage)
                        <div class="row-end">
                            <button type="button" class="btn small" wire:click="toggle({{ $rule->id }})" @unless($rule->enabled) wire:confirm="{{ $catalog->describe($rule) }} Turn this on?" @endunless aria-label="{{ $rule->enabled ? 'Turn off' : 'Turn on' }} {{ $rule->name }}">{{ $rule->enabled ? 'Turn off' : 'Turn on' }}</button>
                            <button type="button" class="btn small" wire:click="start({{ $rule->id }})" aria-label="Edit {{ $rule->name }}">Edit</button>
                            <button type="button" class="btn small danger" wire:click="remove({{ $rule->id }})" wire:confirm="Delete {{ $rule->name }} and its history?" aria-label="Delete {{ $rule->name }}">Delete</button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <h2 class="section-title">What the rules did</h2>
    @if($this->runs->isEmpty())
        <div class="empty">Nothing has run yet.</div>
    @else
        <div class="rows">
            @foreach($this->runs as $run)
                <div class="row" style="flex-wrap:wrap" wire:key="run-{{ $run->id }}">
                    <div class="row-main" style="min-width:240px">
                        <b>{{ $run->rule->name }} on {{ $run->task?->title ?? 'a removed task' }}</b>
                        <span style="white-space:normal">{{ $run->status === 'waiting' ? 'Runs '.$run->run_at->diffForHumans() : ($run->error ?? $run->summary ?? '') }}</span>
                    </div>
                    <div class="row-end">
                        <span @class(['chip', 'go' => $run->status === 'done', 'stop' => $run->status === 'failed', 'wait' => in_array($run->status, ['waiting', 'needs_approval'], true)])>{{ \App\Models\TaskRuleRun::LABELS[$run->status] }}</span>
                        @if($this->canManage && $run->status === 'needs_approval')
                            <button type="button" class="btn small primary" wire:click="approveRun({{ $run->id }})">Approve</button>
                            <button type="button" class="btn small" wire:click="declineRun({{ $run->id }})">Decline</button>
                        @endif
                        @if($this->canManage && $run->status === 'failed')
                            <button type="button" class="btn small" wire:click="retryRun({{ $run->id }})">Try again</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
    <div x-data="{ timer: null }" x-init="timer = setInterval(() => { if (! document.hidden && ! document.activeElement?.matches('input, textarea, select')) $wire.$refresh() }, 20000)" hidden></div>
</div>
