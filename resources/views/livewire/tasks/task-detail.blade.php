<div>
    @if($task)
        <div class="veil" wire:click="close"></div>
        <aside class="sheet" role="dialog" aria-modal="true" aria-label="Task">
            <div class="sheet-head">
                @can('update', $task)
                    <div class="sheet-title">
                        <label class="sr" for="task-title">Title</label>
                        <textarea id="task-title" rows="1" class="ghost-input sheet-title" style="resize:none;field-sizing:content" wire:key="title-{{ $task->id }}" wire:change="saveField('title', $event.target.value)">{{ $task->title }}</textarea>
                        @error('title')<p class="error">{{ $message }}</p>@enderror
                    </div>
                @else
                    <h2 class="sheet-title">{{ $task->title }}</h2>
                @endcan
                <button class="icon-btn" wire:click="close" aria-label="Close"><x-icon name="close" /></button>
            </div>
            <div class="sheet-body">
                @error('status')<p role="alert" class="alert">{{ $message }}</p>@enderror
                @if($this->openBlockers->isNotEmpty() && $task->status !== 'done')
                    <p class="blocked-line" style="margin:0 0 8px">This task cannot be marked done until {{ $this->openBlockers->count() === 1 ? 'its blocker is' : 'its blockers are' }} done.</p>
                @endif

                <div class="props">
                    <span>Status</span>
                    @can('move', $task)
                        <select class="select" wire:key="status-{{ $statusAttempts }}" wire:change="updateStatus($event.target.value)" aria-label="Status">
                            @foreach(['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'] as $val => $lbl)
                                <option value="{{ $val }}" @selected($task->status === $val)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    @else
                        <div>{{ ['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'][$task->status] ?? $task->status }}</div>
                    @endcan

                    <label for="task-priority" style="color:var(--muted);font-size:12px">Priority</label>
                    @can('update', $task)
                        <select class="select" id="task-priority" wire:change="saveField('priority', $event.target.value)">
                            @foreach(array_reverse(\App\Models\Task::PRIORITIES) as $p)
                                <option value="{{ $p }}" @selected($task->priority === $p)>{{ ucfirst($p) }}</option>
                            @endforeach
                        </select>
                    @else
                        <div>{{ ucfirst($task->priority) }}</div>
                    @endcan

                    <label for="task-due" style="color:var(--muted);font-size:12px">Due</label>
                    @can('update', $task)
                        <div>
                            <input class="input" id="task-due" type="date" value="{{ $task->due_date?->toDateString() }}" wire:key="due-{{ $task->id }}-{{ $task->due_date?->toDateString() }}" wire:change="saveField('due_date', $event.target.value)">
                            @error('due_date')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @else
                        <div>{{ $task->due_date ? 'Due '.$task->due_date->format('M j, Y') : 'No due date' }}</div>
                    @endcan

                    <label for="task-start" style="color:var(--muted);font-size:12px">Starts</label>
                    @can('update', $task)
                        <div>
                            <input class="input" id="task-start" type="date" value="{{ $task->start_date?->toDateString() }}" wire:key="start-{{ $task->id }}-{{ $task->start_date?->toDateString() }}" wire:change="saveField('start_date', $event.target.value)">
                            @error('start_date')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @else
                        <div>{{ $task->start_date?->format('M j, Y') ?? '-' }}</div>
                    @endcan

                    <label for="task-assignee" style="color:var(--muted);font-size:12px">Assignee</label>
                    @can('assign', $task)
                        <select class="select" id="task-assignee" wire:change="assignTask($event.target.value ? $event.target.value : null)">
                            <option value="">Unassigned</option>
                            @foreach($this->assignableUsers as $member)
                                <option value="{{ $member->id }}" @selected($task->assignee_id === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <p id="task-assignee">{{ $task->assignee?->name ?? 'Unassigned' }}</p>
                    @endcan

                    <span>With</span>
                    <div style="display:flex;flex-wrap:wrap;gap:4px">
                        @can('assign', $task)
                            @foreach($this->assignableUsers as $member)
                                @if($member->id !== $task->assignee_id)
                                    <button type="button" class="chip" style="border:0;{{ $task->collaborators->contains('id', $member->id) ? 'background:var(--go-wash);color:var(--go)' : '' }}"
                                            aria-pressed="{{ $task->collaborators->contains('id', $member->id) ? 'true' : 'false' }}" wire:click="toggleCollaborator({{ $member->id }})">{{ $member->name }}</button>
                                @endif
                            @endforeach
                        @else
                            @forelse($task->collaborators as $person)<span class="chip">{{ $person->name }}</span>@empty<span style="color:var(--muted)">Nobody else</span>@endforelse
                        @endcan
                    </div>

                    <label for="task-estimate" style="color:var(--muted);font-size:12px">Estimate</label>
                    @can('update', $task)
                        <div>
                            <input class="input" id="task-estimate" type="number" min="0" placeholder="Minutes" value="{{ $task->estimated_minutes }}" wire:change="saveField('estimated_minutes', $event.target.value)">
                            @error('estimated_minutes')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @else
                        <div>{{ $task->estimated_minutes ? $task->estimated_minutes.' minutes' : '-' }}</div>
                    @endcan

                    <label for="task-actual" style="color:var(--muted);font-size:12px">Time spent</label>
                    @can('update', $task)
                        <div>
                            <input class="input" id="task-actual" type="number" min="0" placeholder="Minutes" value="{{ $task->actual_minutes }}" wire:change="saveField('actual_minutes', $event.target.value)">
                            @error('actual_minutes')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    @else
                        <div>{{ $task->actual_minutes ? $task->actual_minutes.' minutes' : '-' }}</div>
                    @endcan
                </div>

                @can('update', $task)
                    <div class="field">
                        <label for="task-description">Description</label>
                        <textarea class="textarea" id="task-description" rows="3" style="field-sizing:content" wire:key="description-{{ $task->id }}" wire:change="saveField('description', $event.target.value)" placeholder="What needs to happen">{{ $task->description }}</textarea>
                    </div>
                    <div class="field">
                        <label for="task-criteria">What done means</label>
                        <textarea class="textarea" id="task-criteria" rows="1" style="field-sizing:content;min-height:34px" wire:key="criteria-{{ $task->id }}" wire:change="saveField('completion_criteria', $event.target.value)" placeholder="Optional">{{ $task->completion_criteria }}</textarea>
                    </div>
                @else
                    @if($task->description)
                        <p style="white-space:pre-wrap;margin-bottom:16px">{{ $task->description }}</p>
                    @endif
                    @if($task->completion_criteria)
                        <p style="white-space:pre-wrap;margin-bottom:16px"><b style="font-weight:500">What done means:</b> {{ $task->completion_criteria }}</p>
                    @endif
                @endcan

                @can('move', $task)
                    @if($task->status !== 'done')
                        <form wire:submit="finish" class="step-add" style="margin-bottom:16px">
                            <input class="input" aria-label="Note for finishing, optional" wire:model="completionNote" placeholder="Note when finishing, optional">
                            <button type="submit" class="btn primary">Finish</button>
                        </form>
                    @endif
                @endcan
                @if($task->completed_at)
                    <p style="margin-bottom:16px;color:var(--go)">Finished {{ $task->completed_at->diffForHumans() }}@if($task->completion_note). {{ $task->completion_note }}@endif</p>
                @endif

                <h3 class="section-title" style="font-size:14px;margin-top:12px">Labels</h3>
                @error('labels')<p class="error">{{ $message }}</p>@enderror
                @can('label', $task)
                    @if($this->teamLabels->isEmpty())
                        <p style="color:var(--muted)">The team has no labels yet.
                            @can('create', [\App\Models\Label::class, $task->taskList->team])
                                <a class="link" href="{{ route('labels.index') }}">Create one</a>.
                            @endcan
                        </p>
                    @else
                        <div style="display:flex;flex-wrap:wrap;gap:4px">
                            @foreach($this->teamLabels as $label)
                                @php $on = $task->labels->contains('id', $label->id); @endphp
                                <button type="button" wire:click="toggleLabel({{ $label->id }})" aria-pressed="{{ $on ? 'true' : 'false' }}" style="border:0;background:none;padding:0;opacity:{{ $on ? 1 : 0.55 }}">
                                    <x-label-chip :label="$label" />
                                </button>
                            @endforeach
                        </div>
                    @endif
                @else
                    @forelse($task->labels as $label)
                        <x-label-chip :label="$label" />
                    @empty
                        <p style="color:var(--muted)">No labels.</p>
                    @endforelse
                @endcan

                <h3 class="section-title" style="font-size:14px">Blocked by</h3>
                @error('blocker')<p role="alert" class="error">{{ $message }}</p>@enderror
                @forelse($task->blockedBy as $blocker)
                    <div wire:key="blocker-{{ $blocker->id }}" style="display:flex;align-items:center;gap:8px;min-height:30px">
                        <span class="tick" style="width:14px;height:14px" aria-checked="{{ $blocker->status === 'done' ? 'true' : 'false' }}" title="{{ $blocker->status === 'done' ? 'Done' : 'Not done yet' }}"></span>
                        <span style="flex:1">{{ $blocker->title }}</span>
                        @if($blocker->isArchived())<span class="chip">archived</span>@endif
                        @can('update', $task)
                            <button type="button" class="btn small quiet" wire:click="removeBlocker({{ $blocker->id }})" aria-label="Remove blocker {{ $blocker->title }}">Remove</button>
                        @endcan
                    </div>
                @empty
                    <p style="color:var(--muted)">Nothing blocks this task.</p>
                @endforelse
                @can('update', $task)
                    @if($this->blockerCandidates->isNotEmpty())
                        <label class="sr" for="add-blocker">Add a blocker</label>
                        <select class="select" style="margin-top:6px" id="add-blocker" wire:key="add-blocker-{{ $task->blockedBy->count() }}" wire:change="addBlocker($event.target.value)">
                            <option value="">Add a blocker...</option>
                            @foreach($this->blockerCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->title }}</option>
                            @endforeach
                        </select>
                    @endif
                @endcan
                @if($task->blocking->isNotEmpty())
                    <h3 class="section-title" style="font-size:14px">Blocking</h3>
                    @foreach($task->blocking as $waiting)
                        <p wire:key="blocking-{{ $waiting->id }}">{{ $waiting->title }}</p>
                    @endforeach
                @endif

                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:24px">
                    <h3 style="font-size:14px;font-weight:600">Subtasks ({{ $task->subtasks->count() }})</h3>
                    @can('update', $task)
                        <button class="btn small" wire:click="aiBreakdown" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="aiBreakdown">AI Breakdown</span>
                            <span wire:loading wire:target="aiBreakdown">Breaking down...</span>
                        </button>
                    @endcan
                </div>
                @if($aiError)<p class="error">{{ $aiError }}</p>@endif
                @if($task->subtasks->isNotEmpty())
                    <div @can('update', $task) x-data="stepList('placeSubtask')" @endcan>
                    @foreach($task->subtasks as $sub)
                        <div class="step" wire:key="sub-{{ $sub->id }}" data-task="{{ $sub->id }}">
                            @can('update', $task)
                                <button type="button" class="grip" aria-label="Move {{ $sub->title }}, use the up and down arrows" x-on:keydown="keys($event, {{ $sub->id }})"><x-icon name="grip" /></button>
                            @endcan
                            @can('move', $sub)
                                <button type="button" class="tick" role="checkbox" aria-checked="{{ $sub->status === 'done' ? 'true' : 'false' }}" aria-label="{{ $sub->title }}" wire:click="toggleSubtask({{ $sub->id }})"></button>
                            @else
                                <span class="tick" role="checkbox" aria-checked="{{ $sub->status === 'done' ? 'true' : 'false' }}" aria-label="{{ $sub->title }}"></span>
                            @endcan
                            <button type="button" class="step-title link" style="text-decoration:none;text-align:left" wire:click="openTask({{ $sub->id }})">{{ $sub->title }}</button>
                            @if($sub->estimated_minutes)<span class="num" style="color:var(--muted);font-size:12px">{{ $sub->estimated_minutes }}m</span>@endif
                        </div>
                    @endforeach
                    </div>
                @else
                    <p style="color:var(--muted)">No subtasks yet.</p>
                @endif
                @can('update', $task)
                    <form wire:submit="addSubtask" class="step-add">
                        <input class="input" aria-label="Add a subtask" wire:model="newSubtask" placeholder="Add a subtask" autocomplete="off">
                        <button type="submit" class="btn small">Add</button>
                    </form>
                    @error('newSubtask')<p class="error">{{ $message }}</p>@enderror
                @endcan

                <h3 style="font-size:14px;font-weight:600;margin-top:24px">Checklist @if($task->checklistItems->isNotEmpty())<span class="num" style="color:var(--muted);font-weight:400">{{ $task->checklistItems->whereNotNull('done_at')->count() }}/{{ $task->checklistItems->count() }}</span>@endif</h3>
                @foreach($task->checklistItems as $item)
                    <div class="step" wire:key="check-{{ $item->id }}">
                        @can('update', $task)
                            <button type="button" class="tick" role="checkbox" aria-checked="{{ $item->done_at ? 'true' : 'false' }}" aria-label="{{ $item->title }}" wire:click="toggleChecklistItem({{ $item->id }})"></button>
                        @else
                            <span class="tick" role="checkbox" aria-checked="{{ $item->done_at ? 'true' : 'false' }}" aria-label="{{ $item->title }}"></span>
                        @endcan
                        <span class="step-title">{{ $item->title }}</span>
                        @can('update', $task)
                            <button type="button" class="btn small quiet" wire:click="promoteChecklistItem({{ $item->id }})">Make subtask</button>
                            <button type="button" class="btn small quiet" wire:click="removeChecklistItem({{ $item->id }})" aria-label="Remove {{ $item->title }}">Remove</button>
                        @endcan
                    </div>
                @endforeach
                @can('update', $task)
                    <form wire:submit="addChecklistItem" class="step-add">
                        <input class="input" aria-label="Add a checklist item" wire:model="newChecklistItem" placeholder="Add a checklist item" autocomplete="off">
                        <button type="submit" class="btn small">Add</button>
                    </form>
                    @error('newChecklistItem')<p class="error">{{ $message }}</p>@enderror
                @endcan

                @canany(['archive', 'delete'], $task)
                    <div style="margin-top:20px">
                        @error('archive')<p class="error">{{ $message }}</p>@enderror
                        @error('delete')<p class="error">{{ $message }}</p>@enderror
                        @if($confirming === 'archive')
                            <p>
                                @if($this->affectedSubtasks > 0)
                                    Archiving this task also archives its {{ $this->affectedSubtasks }} {{ \Illuminate\Support\Str::plural('subtask', $this->affectedSubtasks) }}.
                                @else
                                    Archive this task?
                                @endif
                                It leaves the board and can be restored from the board's Archived list.
                            </p>
                            <div class="page-actions" style="margin-top:8px">
                                <button type="button" class="btn" wire:click="archiveTask(true)">Archive</button>
                                <button type="button" class="btn quiet" wire:click="cancelConfirm">Cancel</button>
                            </div>
                        @elseif($confirming === 'delete')
                            <p>
                                @if($this->affectedSubtasks > 0)
                                    Deleting this task also deletes its {{ $this->affectedSubtasks }} {{ \Illuminate\Support\Str::plural('subtask', $this->affectedSubtasks) }}.
                                @else
                                    Delete this task?
                                @endif
                                It disappears for everyone. An admin or the owner can restore it from Removed.
                            </p>
                            <div class="page-actions" style="margin-top:8px">
                                <button type="button" class="btn danger" wire:click="deleteTask(true)">Delete</button>
                                <button type="button" class="btn quiet" wire:click="cancelConfirm">Cancel</button>
                            </div>
                        @else
                            <div class="page-actions">
                                @can('create', [\App\Models\Task::class, $task->taskList])
                                    <button type="button" class="btn small" wire:click="duplicate">Duplicate</button>
                                @endcan
                                @can('archive', $task)
                                    <button type="button" class="btn small" wire:click="confirmArchive">Archive task</button>
                                @endcan
                                @can('delete', $task)
                                    <button type="button" class="btn small danger" wire:click="confirmDelete">Delete task</button>
                                @endcan
                            </div>
                        @endif
                    </div>
                @endcanany

                <h3 class="section-title" style="font-size:14px">Comments</h3>
                @forelse($task->comments as $comment)
                    <div class="comment">
                        <div class="comment-who">
                            <b>{{ $comment->user->name }}</b>
                            <span>{{ $comment->created_at->diffForHumans() }}</span>
                        </div>
                        <p>{!! $this->mentions->render($comment->body) !!}</p>
                    </div>
                @empty
                    <p style="color:var(--muted)">No comments yet.</p>
                @endforelse

                @can('comment', $task)
                    <form wire:submit="addComment" style="margin-top:10px">
                        <textarea class="textarea" wire:model="commentBody" rows="2" placeholder="Add a comment..." aria-label="Add a comment"></textarea>
                        @error('commentBody')<p class="error">{{ $message }}</p>@enderror
                        <p style="margin:4px 0 8px;color:var(--muted);font-size:12px">Type @ and a teammate's full name to notify them.</p>
                        <button type="submit" class="btn">Comment</button>
                    </form>
                @endcan

                @if($task->activities->isNotEmpty())
                    <h3 class="section-title" style="font-size:14px">History</h3>
                    @foreach($task->activities->take(30) as $entry)
                        <div class="history" wire:key="history-{{ $entry->id }}">
                            <span><b>{{ $entry->user?->name ?? 'Dot.Tasks' }}</b> {{ lcfirst($entry->summary) }}, {{ $entry->created_at->diffForHumans() }}</span>
                        </div>
                    @endforeach
                @endif
            </div>
        </aside>
    @endif
</div>
