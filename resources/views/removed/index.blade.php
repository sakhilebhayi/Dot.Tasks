<x-app-layout title="Removed">
    <div class="page narrow">
        <div class="page-head">
            <div>
                <h1>Removed</h1>
                <p>Tasks that were deleted on this team. Admins and the owner can restore them; they are deleted for good {{ (int) config('tasks.removed_retention_days') }} days after removal.</p>
            </div>
        </div>
        <livewire:tasks.removed-tasks />
    </div>
</x-app-layout>
