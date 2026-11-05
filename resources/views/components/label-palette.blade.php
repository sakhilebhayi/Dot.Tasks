@props(['model', 'selected'])

{{-- The fixed label palette as a radio group of swatches, bound to a Livewire property. --}}
<div role="radiogroup" aria-label="Label colour" style="display:flex;gap:6px;flex-wrap:wrap">
    @foreach(\App\Models\Label::PALETTE as $colourName => $hex)
        <label title="{{ $colourName }}" style="position:relative;display:inline-flex;cursor:pointer">
            <input class="sr" type="radio" wire:model="{{ $model }}" value="{{ $hex }}" aria-label="{{ $colourName }}">
            <span style="width:20px;height:20px;border-radius:4px;background:{{ $hex }};outline:{{ $selected === $hex ? '2px solid var(--text)' : 'none' }};outline-offset:1px"></span>
        </label>
    @endforeach
</div>
