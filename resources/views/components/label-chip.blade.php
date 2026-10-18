@props(['label'])

{{-- A label as a small chip: its colour as a dot, its name as text (never colour alone). --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 max-w-full text-[11px] leading-4 px-1.5 py-0.5 rounded border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300']) }}>
    <span class="shrink-0 w-2 h-2 rounded-full" style="background:{{ $label->color }};"></span>
    <span class="truncate">{{ $label->name }}</span>
</span>
