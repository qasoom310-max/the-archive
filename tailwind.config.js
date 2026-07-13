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
                // Accent (brand) palette — CSS-variable driven so a per-user
                // "accent colour" choice can remap the whole app without editing
                // views. The default ramp (bright lemon-yellow) lives in
                // `:root` in app.css; each `[data-accent="…"]` swaps the RGB
                // triplets. `400` is the bright fill (pair with text-chrome-900);
                // `600+` stay dark for accent TEXT on white.
                primary: {
                    50: 'rgb(var(--primary-50) / <alpha-value>)',
                    100: 'rgb(var(--primary-100) / <alpha-value>)',
                    200: 'rgb(var(--primary-200) / <alpha-value>)',
                    300: 'rgb(var(--primary-300) / <alpha-value>)',
                    400: 'rgb(var(--primary-400) / <alpha-value>)',
                    500: 'rgb(var(--primary-500) / <alpha-value>)',
                    600: 'rgb(var(--primary-600) / <alpha-value>)',
                    700: 'rgb(var(--primary-700) / <alpha-value>)',
                    800: 'rgb(var(--primary-800) / <alpha-value>)',
                    900: 'rgb(var(--primary-900) / <alpha-value>)',
                    950: 'rgb(var(--primary-950) / <alpha-value>)',
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
