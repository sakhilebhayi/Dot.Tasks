<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="margin-bottom:1.5rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">My tasks</h1>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">Assigned to you on every board of this team, most urgent first.</p>
        </div>

        <livewire:tasks.my-tasks />
        <livewire:tasks.task-detail />
    </div>
</x-app-layout>
