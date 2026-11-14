import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';
import dotPreset from './dot-ui/tailwind/tailwind-preset.cjs';

/** @type {import('tailwindcss').Config} */
export default {
    // The shared Dot type scale and Roboto (font-sans, font-mono, text-dot-*), copied in by dot-ui/sync.sh.
    presets: [dotPreset],

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    darkMode: 'class',

    plugins: [forms, typography],
};
