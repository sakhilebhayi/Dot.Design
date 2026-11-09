import typography from '@tailwindcss/typography';

/**
 * Tailwind 3 is still built (Jetstream's Livewire forms and the older pages use utility classes), but The Light Table owns the look:
 *  - preflight is off, resources/css/ds/base.css is the reset;
 *  - the colour names the old pages use (gray, indigo, red, green, yellow, white) are remapped onto the semantic tokens, so every
 *    leftover utility follows the theme (including dark) instead of carrying its own palette.
 * New work uses the lt-* classes and <x-ds.*> components, not these utilities.
 */
const token = (name) => `var(--lt-${name})`;

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './app/View/**/*.php',
        './app/Livewire/**/*.php',
        './app/Support/Ui/**/*.php',
    ],

    corePlugins: { preflight: false },
    // Theme is chosen by data-theme on <html> (see resources/css/ds/tokens.css); remaining dark: utilities follow it, not the OS directly.
    darkMode: ['selector', '[data-theme="dark"]'],

    theme: {
        extend: {
            fontFamily: {
                sans: ['var(--lt-font-ui)'],
                display: ['var(--lt-font-display)'],
                mono: ['var(--lt-font-mono)'],
            },
            colors: {
                white: token('panel'),
                black: token('ink'),
                gray: {
                    50: token('panel-sunk'), 100: token('panel-sunk'), 200: token('rule'), 300: token('rule-strong'),
                    400: token('ink-3'), 500: token('ink-3'), 600: token('ink-2'), 700: token('ink-2'), 800: token('ink'), 900: token('ink'),
                },
                indigo: {
                    50: token('accent-wash'), 100: token('accent-wash'), 200: token('accent'), 300: token('accent'), 400: token('accent'),
                    500: token('accent'), 600: token('accent'), 700: token('accent'), 800: token('accent'), 900: token('accent'),
                },
                red: {
                    50: token('bad-wash'), 100: token('bad-wash'), 200: token('bad'), 300: token('bad'), 400: token('bad'),
                    500: token('bad'), 600: token('bad'), 700: token('bad'), 800: token('bad'), 900: token('bad'),
                },
                rose: { 50: token('bad-wash'), 200: token('bad') },
                green: {
                    50: token('ok-wash'), 100: token('ok-wash'), 400: token('ok'), 500: token('ok'), 600: token('ok'), 700: token('ok'),
                },
                yellow: { 100: token('warn-wash'), 500: token('warn'), 600: token('warn') },
            },
        },
    },

    plugins: [typography],
};
