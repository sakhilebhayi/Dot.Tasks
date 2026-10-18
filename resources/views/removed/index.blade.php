<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:1040px;">
        <div style="margin-bottom:1.5rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Removed</h1>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">Tasks that were deleted on this team. Admins and the owner can restore them; they are deleted for good {{ (int) config('tasks.removed_retention_days') }} days after removal.</p>
        </div>

        <livewire:tasks.removed-tasks />
    </div>
</x-app-layout>
