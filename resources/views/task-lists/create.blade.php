<x-app-layout>
    <div class="page-pad">

        <!-- Page header -->
        <div class="page-head" style="justify-content:flex-start;">
            <a href="{{ route('dashboard') }}" style="display:flex;align-items:center;gap:0.4rem;text-decoration:none;color:#8d90a2;font-size:0.8rem;font-weight:600;transition:color 0.2s;" onmouseover="this.style.color='#b6c4ff'" onmouseout="this.style.color='#8d90a2'">
                <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
                Dashboard
            </a>
            <span style="color:rgba(67,70,86,0.6);">/</span>
            <h1 style="font-family:'Manrope',sans-serif;font-size:1.25rem;font-weight:800;color:#dae2fd;margin:0;">New Task List</h1>
        </div>

        <!-- Form card -->
        <div style="max-width:540px;">
            <div style="background:rgba(19,27,46,0.9);border:1px solid rgba(67,70,86,0.25);border-radius:1rem;padding:2rem;">
                <form action="{{ route('task-lists.store') }}" method="POST">
                    @csrf

                    <!-- Name -->
                    <div style="margin-bottom:1.5rem;">
                        <label for="task-lists-create-name" style="display:block;font-family:'Manrope',sans-serif;font-size:0.75rem;font-weight:700;color:#b7c8e1;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.5rem;">List Name</label>
                        <input id="task-lists-create-name"
                            name="name"
                            type="text"
                            placeholder="e.g. Sprint 1, Marketing Tasks..."
                            value="{{ old('name') }}"
                            required
                            style="width:100%;background:rgba(11,19,38,0.8);border:1px solid rgba(67,70,86,0.4);border-radius:0.6rem;padding:0.75rem 1rem;font-size:0.875rem;color:#dae2fd;outline:none;transition:border-color 0.2s;"
                            onfocus="this.style.borderColor='rgba(41,98,255,0.6)'"
                            onblur="this.style.borderColor='rgba(67,70,86,0.4)'"
                        />
                        @error('name')
                            <p style="font-size:0.72rem;color:#dc2626;margin-top:0.4rem;">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div style="margin-bottom:1.5rem;">
                        <label for="task-lists-create-description" style="display:block;font-family:'Manrope',sans-serif;font-size:0.75rem;font-weight:700;color:#b7c8e1;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.5rem;">Description <span style="color:#8d90a2;font-weight:400;text-transform:none;letter-spacing:0;">(optional)</span></label>
                        <textarea id="task-lists-create-description"
                            name="description"
                            rows="3"
                            placeholder="What is this list for?"
                            style="width:100%;background:rgba(11,19,38,0.8);border:1px solid rgba(67,70,86,0.4);border-radius:0.6rem;padding:0.75rem 1rem;font-size:0.875rem;color:#dae2fd;outline:none;resize:vertical;transition:border-color 0.2s;font-family:'Inter',sans-serif;"
                            onfocus="this.style.borderColor='rgba(41,98,255,0.6)'"
                            onblur="this.style.borderColor='rgba(67,70,86,0.4)'"
                        >{{ old('description') }}</textarea>
                    </div>

                    <!-- Color -->
                    <div style="margin-bottom:2rem;" x-data="{ selected: '{{ old('color', '#6366f1') }}' }">
                        <label style="display:block;font-family:'Manrope',sans-serif;font-size:0.75rem;font-weight:700;color:#b7c8e1;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:0.75rem;">Colour</label>
                        <input type="hidden" name="color" :value="selected" />
                        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:0.75rem;">
                            @foreach(['#6366f1', '#2962ff', '#059669', '#dc2626', '#d97706', '#7c3aed'] as $swatch)
                            <button
                                type="button"
                                @click="selected = '{{ $swatch }}'"
                                style="width:32px;height:32px;border-radius:9999px;background:{{ $swatch }};border:none;cursor:pointer;transition:transform 0.15s,box-shadow 0.15s;position:relative;"
                                :style="selected === '{{ $swatch }}' ? { transform: 'scale(1.2)', boxShadow: '0 0 0 3px rgba(255,255,255,0.2), 0 0 0 5px {{ $swatch }}' } : {}"
                                title="{{ $swatch }}"
                            >
                                <span
                                    class="material-symbols-rounded"
                                    x-show="selected === '{{ $swatch }}'"
                                    style="font-size:14px;color:#fff;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;"
                                >check</span>
                            </button>
                            @endforeach
                        </div>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        style="width:100%;display:flex;align-items:center;justify-content:center;gap:0.5rem;border-radius:9999px;background:linear-gradient(135deg,#2962ff,#004ee8);padding:0.8rem 1.5rem;font-family:'Manrope',sans-serif;font-size:0.875rem;font-weight:700;color:#f7f5ff;border:none;cursor:pointer;box-shadow:0 8px 20px rgba(41,98,255,0.3);transition:opacity 0.2s;"
                        onmouseover="this.style.opacity='0.9'"
                        onmouseout="this.style.opacity='1'"
                    >
                        <span class="material-symbols-rounded" style="font-size:18px;">add_circle</span>
                        Create List
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
