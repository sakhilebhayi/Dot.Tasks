@props(['label'])

{{-- A label as a small chip: its colour as a dot, its name as text (never colour alone). --}}
<span {{ $attributes->merge(['class' => 'chip']) }}>
    <span class="swatch" style="background: {{ $label->color }}"></span>
    <span>{{ $label->name }}</span>
</span>
