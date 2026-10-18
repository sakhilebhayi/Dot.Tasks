<div>
    @if($task)
        {{-- Backdrop --}}
        <div class="fixed inset-0 bg-black/40 z-40" wire:click="close"></div>

        {{-- Drawer --}}
        <div class="fixed inset-y-0 right-0 w-full max-w-xl bg-white dark:bg-gray-900 shadow-xl z-50 overflow-y-auto">
            <div class="p-6">
                {{-- Header --}}
                <div class="flex items-start justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white pr-4">{{ $task->title }}</h2>
                    <button wire:click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 shrink-0">✕</button>
                </div>

                {{-- Status + Priority --}}
                <div class="flex gap-3 mb-5">
                    @can('move', $task)
                        <select wire:change="updateStatus($event.target.value)" aria-label="Status"
                                class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            @foreach(['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'] as $val => $lbl)
                                <option value="{{ $val }}" @selected($task->status === $val)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    @else
                        <span class="text-sm px-2 py-1 rounded bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                            {{ ['todo' => 'To Do', 'in_progress' => 'In Progress', 'review' => 'Review', 'done' => 'Done'][$task->status] ?? $task->status }}
                        </span>
                    @endcan
                    <span class="text-sm px-2 py-1 rounded
                        {{ match($task->priority) {
                            'urgent' => 'bg-red-100 text-red-700',
                            'high'   => 'bg-orange-100 text-orange-700',
                            'medium' => 'bg-yellow-100 text-yellow-700',
                            'low'    => 'bg-gray-100 text-gray-600',
                            default  => 'bg-gray-100 text-gray-600',
                        } }}">
                        {{ ucfirst($task->priority) }}
                    </span>
                    @if($task->due_date)
                        <span class="text-sm text-gray-500 dark:text-gray-400">Due {{ $task->due_date->format('M j, Y') }}</span>
                    @endif
                </div>

                {{-- Assignee --}}
                <div class="mb-5">
                    <label for="task-assignee" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Assignee</label>
                    @can('assign', $task)
                        <select id="task-assignee" wire:change="assignTask($event.target.value ? $event.target.value : null)"
                                class="text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            <option value="">Unassigned</option>
                            @foreach($this->assignableUsers as $member)
                                <option value="{{ $member->id }}" @selected($task->assignee_id === $member->id)>{{ $member->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <p id="task-assignee" class="text-sm text-gray-700 dark:text-gray-300">{{ $task->assignee?->name ?? 'Unassigned' }}</p>
                    @endcan
                </div>

                {{-- Labels --}}
                <div class="mb-5">
                    <h3 class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Labels</h3>
                    @error('labels')<p class="text-xs text-red-600 mb-1">{{ $message }}</p>@enderror
                    @can('label', $task)
                        @if($this->teamLabels->isEmpty())
                            <p class="text-xs text-gray-400 dark:text-gray-500">The team has no labels yet.
                                @can('create', [\App\Models\Label::class, $task->taskList->team])
                                    <a href="{{ route('labels.index') }}" class="underline">Create one</a>.
                                @endcan
                            </p>
                        @else
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($this->teamLabels as $label)
                                    @php $on = $task->labels->contains('id', $label->id); @endphp
                                    <button type="button" wire:click="toggleLabel({{ $label->id }})" aria-pressed="{{ $on ? 'true' : 'false' }}"
                                            class="rounded {{ $on ? 'ring-1 ring-gray-400 dark:ring-gray-500' : 'opacity-60 hover:opacity-100' }}">
                                        <x-label-chip :label="$label" class="{{ $on ? 'bg-gray-100 dark:bg-gray-800' : '' }}" />
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    @else
                        @forelse($task->labels as $label)
                            <x-label-chip :label="$label" />
                        @empty
                            <p class="text-xs text-gray-400 dark:text-gray-500">No labels.</p>
                        @endforelse
                    @endcan
                </div>

                {{-- Description --}}
                @if($task->description)
                    <div class="mb-5 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                        {{ $task->description }}
                    </div>
                @endif

                {{-- AI Breakdown --}}
                <div class="mb-5">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                            Subtasks ({{ $task->subtasks->count() }})
                        </h3>
                        @can('update', $task)
                            <button wire:click="aiBreakdown" wire:loading.attr="disabled"
                                    class="text-xs px-3 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-300 rounded-full hover:bg-indigo-100 disabled:opacity-50">
                                <span wire:loading.remove wire:target="aiBreakdown">✨ AI Breakdown</span>
                                <span wire:loading wire:target="aiBreakdown">Breaking down...</span>
                            </button>
                        @endcan
                    </div>
                    @if($aiError)
                        <p class="text-xs text-red-600 mb-2">{{ $aiError }}</p>
                    @endif
                    @if($task->subtasks->isNotEmpty())
                        <div class="space-y-1.5">
                            @foreach($task->subtasks as $sub)
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="w-2 h-2 rounded-full {{ $sub->status === 'done' ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600' }}"></span>
                                    <span class="{{ $sub->status === 'done' ? 'line-through text-gray-400' : 'text-gray-700 dark:text-gray-300' }}">
                                        {{ $sub->title }}
                                    </span>
                                    @if($sub->estimated_minutes)
                                        <span class="ml-auto text-xs text-gray-400">{{ $sub->estimated_minutes }}m</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-gray-400 dark:text-gray-500">No subtasks yet.@can('update', $task) Use AI Breakdown to generate them.@endcan</p>
                    @endif
                </div>

                {{-- Comments --}}
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Comments</h3>

                    @forelse($task->comments as $comment)
                        <div class="mb-3">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-xs font-medium text-gray-900 dark:text-white">{{ $comment->user->name }}</span>
                                <span class="text-xs text-gray-400">{{ $comment->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
                                {{ $comment->body }}
                            </p>
                        </div>
                    @empty
                        <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">No comments yet.</p>
                    @endforelse

                    @can('comment', $task)
                        <form wire:submit="addComment" class="mt-3">
                            <textarea wire:model="commentBody" rows="2" placeholder="Add a comment..." aria-label="Add a comment"
                                      class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-indigo-500 mb-2"></textarea>
                            @error('commentBody')<p class="text-xs text-red-600 mb-1">{{ $message }}</p>@enderror
                            <button type="submit"
                                    class="px-4 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700">
                                Comment
                            </button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>
    @endif
</div>
