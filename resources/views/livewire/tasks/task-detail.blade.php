<div>
    @if($task)
        <div class="veil" wire:click="close"></div>
        <aside class="sheet" role="dialog" aria-modal="true" aria-label="Task">
            <div class="sheet-head">
                <h2 class="sheet-title">{{ $task->title }}</h2>
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

                    <span>Priority</span>
                    <div>{{ ucfirst($task->priority) }}</div>

                    @if($task->due_date)
                        <span>Due</span>
                        <div>Due {{ $task->due_date->format('M j, Y') }}</div>
                    @endif

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
                </div>

                @if($task->description)
                    <p style="white-space:pre-wrap;margin-bottom:16px">{{ $task->description }}</p>
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
                    @foreach($task->subtasks as $sub)
                        <div style="display:flex;align-items:center;gap:8px;min-height:30px">
                            <span class="tick" style="width:14px;height:14px" aria-checked="{{ $sub->status === 'done' ? 'true' : 'false' }}"></span>
                            <span style="flex:1">{{ $sub->title }}</span>
                            @if($sub->estimated_minutes)<span class="num" style="color:var(--muted);font-size:12px">{{ $sub->estimated_minutes }}m</span>@endif
                        </div>
                    @endforeach
                @else
                    <p style="color:var(--muted)">No subtasks yet.@can('update', $task) Use AI Breakdown to generate them.@endcan</p>
                @endif

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
            </div>
        </aside>
    @endif
</div>
