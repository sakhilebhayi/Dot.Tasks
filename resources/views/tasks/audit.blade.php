<x-app-layout title="Audit log">
    <div class="page narrow">
        <div class="page-head">
            <div>
                <h1>Audit log</h1>
                <p>Changes to how this business is set up, kept for {{ config('tasks.audit_months') }} months.</p>
            </div>
        </div>
        <livewire:audit-trail />
    </div>
</x-app-layout>
