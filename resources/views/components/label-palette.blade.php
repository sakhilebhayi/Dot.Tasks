@props(['model', 'selected'])

{{-- The fixed label palette as a radio group of swatches, bound to a Livewire property. --}}
<div role="radiogroup" aria-label="Label colour" style="display:flex;gap:0.4rem;">
    @foreach(\App\Models\Label::PALETTE as $colourName => $hex)
        <label title="{{ $colourName }}" style="position:relative;cursor:pointer;">
            <input type="radio" wire:model="{{ $model }}" value="{{ $hex }}" aria-label="{{ $colourName }}" class="peer" style="position:absolute;opacity:0;inset:0;">
            <span class="peer-focus-visible:ring-2 peer-focus-visible:ring-white"
                  style="display:block;width:20px;height:20px;border-radius:50%;background:{{ $hex }};border:2px solid {{ $selected === $hex ? '#f4f4f5' : 'transparent' }};box-shadow:0 0 0 1px rgba(255,255,255,0.12);"></span>
        </label>
    @endforeach
</div>
