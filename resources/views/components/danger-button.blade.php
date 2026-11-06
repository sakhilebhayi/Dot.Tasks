<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn danger']) }}>
    {{ $slot }}
</button>
