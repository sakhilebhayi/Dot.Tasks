<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Manrope',sans-serif;font-size:1.5rem;font-weight:700;margin:0 0 0.2rem;">Upcoming</h1>
                <p style="font-size:0.8rem;color:#8d90a2;margin:0;">Due in the next 7 days, across every list</p>
            </div>
        </div>

        <livewire:tasks.smart-views view="upcoming" />
    </div>
</x-app-layout>
