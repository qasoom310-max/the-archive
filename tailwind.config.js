import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
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
                // Odoo 19 signature aubergine/violet brand accent.
                primary: {
                    50: '#f7f4f6',
                    100: '#ede5ea',
                    200: '#d9c8d3',
                    300: '#bfa1b4',
                    400: '#9f7390',
                    500: '#875a7b', // Odoo brand
                    600: '#714b67',
                    700: '#5d3e55',
                    800: '#4d3447',
                    900: '#412d3c',
                    950: '#241620',
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
