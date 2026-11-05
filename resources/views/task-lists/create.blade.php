<x-app-layout title="New board">
    <div class="page narrow">
        <div class="page-head">
            <div>
                <a class="crumb" href="{{ route('dashboard') }}"><x-icon name="back" /> Overview</a>
                <h1>New board</h1>
            </div>
        </div>
        <form class="panel" action="{{ route('task-lists.store') }}" method="POST" style="max-width:520px">
            @csrf
            <div class="field">
                <label for="task-lists-create-name">Name</label>
                <input class="input" id="task-lists-create-name" name="name" type="text" placeholder="e.g. Monthly operations" value="{{ old('name') }}" required>
                @error('name')<p class="error">{{ $message }}</p>@enderror
            </div>
            <div class="field">
                <label for="task-lists-create-description">Description (optional)</label>
                <textarea class="textarea" id="task-lists-create-description" name="description" rows="3" placeholder="What is this board for?">{{ old('description') }}</textarea>
            </div>
            <fieldset class="field" style="border:0;padding:0" x-data="{ selected: '{{ old('color', '#1c7c54') }}' }">
                <legend class="label" style="padding:0;margin-bottom:6px">Colour</legend>
                <input type="hidden" name="color" :value="selected">
                <div style="display:flex;gap:6px">
                    @foreach(['#1c7c54', '#2f6db5', '#8a5a00', '#be2a23', '#5e605a', '#171815'] as $swatch)
                        <button type="button" @click="selected = '{{ $swatch }}'" :aria-pressed="selected === '{{ $swatch }}'" aria-label="Colour {{ $swatch }}"
                                style="width:24px;height:24px;border:0;border-radius:4px;background:{{ $swatch }}"
                                :style="selected === '{{ $swatch }}' && { outline: '2px solid var(--text)', outlineOffset: '1px' }"></button>
                    @endforeach
                </div>
            </fieldset>
            <button type="submit" class="btn primary">Create board</button>
        </form>
    </div>
</x-app-layout>
