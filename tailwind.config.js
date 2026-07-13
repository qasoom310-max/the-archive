import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    // Dark mode is driven by a `.dark` class on <html> (toggled from the
    // per-user theme preference by an inline script in the app layout). The
    // actual dark styling is a surgical override block in resources/css/app.css
    // that remaps the neutral chrome/white utilities under `.dark` — light
    // mode stays byte-identical (zero regression).
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/Livewire/**/*.php',
        './Modules/**/resources/**/*.blade.php',
        './Modules/**/src/Livewire/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                // Brand accent: bright lemon-yellow (#F5EF1A). Monotonic
                // light→dark ramp. The bright end (≤400) carries dark text and
                // backs the chrome (topbar, primary buttons, active chips); the
                // dark gold/olive end (≥600) stays legible as accent TEXT on
                // white. 400 is the exact brand colour — use it for solid brand
                // fills with `text-chrome-900` on top (white text is unreadable
                // on yellow).
                primary: {
                    50: '#fefee8',
                    100: '#fdfbc5',
                    200: '#fbf690',
                    300: '#f7ee51',
                    400: '#f5ef1a', // brand — bright fill, pair with text-chrome-900
                    500: '#d9c90a',
                    600: '#a99107', // readable accent text on white
                    700: '#86730c',
                    800: '#6b5b10',
                    900: '#594c13',
                    950: '#332b08',
                },
                // Dense, cool-gray chrome like the Odoo 19 web client.
                chrome: {
                    50: '#f8f9fa',
                    100: '#f1f3f5',
                    200: '#e9ecef',
                    300: '#dee2e6',
                    400: '#ced4da',
                    500: '#adb5bd',
                    600: '#868e96',
                    700: '#495057',
                    800: '#343a40',
                    900: '#212529',
                },
            },
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                // Compact Odoo density.
                xs: ['0.75rem', { lineHeight: '1rem' }],
                sm: ['0.8125rem', { lineHeight: '1.25rem' }],
                base: ['0.875rem', { lineHeight: '1.375rem' }],
            },
            boxShadow: {
                pop: '0 4px 24px -2px rgb(33 37 41 / 0.12), 0 2px 6px -2px rgb(33 37 41 / 0.08)',
            },
            keyframes: {
                'pop-in': {
                    '0%': { opacity: '0', transform: 'translateY(-4px) scale(.98)' },
                    '100%': { opacity: '1', transform: 'translateY(0) scale(1)' },
                },
            },
            animation: {
                'pop-in': 'pop-in .12s ease-out',
            },
        },
    },
    plugins: [forms, typography],
};
