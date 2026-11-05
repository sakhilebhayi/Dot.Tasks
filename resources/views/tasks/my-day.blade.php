<x-app-layout title="My Day">
    <div class="page">
        <div class="page-head">
            <div>
                <h1>My Day</h1>
                <p>{{ now()->format('l, j F') }}</p>
            </div>
        </div>
        <livewire:tasks.my-day />
    </div>
</x-app-layout>
