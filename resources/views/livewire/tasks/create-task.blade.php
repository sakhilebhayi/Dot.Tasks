<div>
    @if($showForm)
        <div class="fixed inset-0 bg-black/40 z-40" wire:click="cancel"></div>
        <div class="fixed inset-0 flex items-center justify-center z-50 p-4">
            <div class="bg-white dark:bg-gray-900 rounded-xl shadow-2xl w-full max-w-md p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">New Task</h3>

                <form wire:submit="save" class="space-y-4">
                    <div>
                        <input aria-label="Task title" wire:model="title" type="text" placeholder="Task title" autofocus
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm focus:ring-indigo-500">
                        @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <textarea aria-label="Description" wire:model="description" rows="2" placeholder="Description (optional)"
                              class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white shadow-sm text-sm"></textarea>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="livewire-tasks-create-task-priority" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Priority</label>
                            <select id="livewire-tasks-create-task-priority" wire:model="priority"
                                    class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                                <option value="urgent">Urgent</option>
                            </select>
                        </div>
                        <div>
                            <label for="livewire-tasks-create-task-duedate" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Due Date</label>
                            <input id="livewire-tasks-create-task-duedate" wire:model="dueDate" type="date"
                                   class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                        </div>
                    </div>

                    <div>
                        <label for="new-task-assignee" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Assignee</label>
                        <select id="new-task-assignee" wire:model="assigneeId"
                                class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            <option value="">Unassigned</option>
                            @foreach($this->teamMembers as $member)
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select>
                        @error('assigneeId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>

                    @if($this->teamLabels->isNotEmpty())
                        <fieldset>
                            <legend class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Labels</legend>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($this->teamLabels as $label)
                                    <label class="cursor-pointer">
                                        <input type="checkbox" wire:model="labelIds" value="{{ $label->id }}" class="peer sr-only">
                                        <x-label-chip :label="$label" class="peer-checked:bg-gray-200 dark:peer-checked:bg-gray-700 peer-checked:border-gray-500 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500" />
                                    </label>
                                @endforeach
                            </div>
                            @error('labelIds')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </fieldset>
                    @endif

                    <div>
                        <label for="livewire-tasks-create-task-estimatedminutes" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Estimate (minutes)</label>
                        <input id="livewire-tasks-create-task-estimatedminutes" wire:model="estimatedMinutes" type="number" min="1" placeholder="e.g. 30"
                               class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                    </div>

                    <div>
                        <label for="livewire-tasks-create-task-recurrencetype" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Repeat</label>
                        <select id="livewire-tasks-create-task-recurrencetype" wire:model.live="recurrenceType"
                                class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                            <option value="">Doesn't repeat</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="custom_days">Every N days</option>
                        </select>
                    </div>

                    @if($recurrenceType)
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="livewire-tasks-create-task-recurrenceinterval" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Every</label>
                                <input id="livewire-tasks-create-task-recurrenceinterval" wire:model="recurrenceInterval" type="number" min="1" max="365"
                                       class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                @error('recurrenceInterval')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="livewire-tasks-create-task-recurrenceanchor" class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Based on</label>
                                <select id="livewire-tasks-create-task-recurrenceanchor" wire:model="recurrenceAnchor"
                                        class="w-full text-sm rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-white">
                                    <option value="due_date">Due date</option>
                                    <option value="completion">Completion date</option>
                                </select>
                            </div>
                        </div>
                    @endif

                    <div class="flex gap-3 pt-1">
                        <button type="button" wire:click="cancel"
                                class="flex-1 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600">
                            Cancel
                        </button>
                        <button type="submit"
                                class="flex-1 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
                            Create Task
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
