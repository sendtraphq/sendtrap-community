import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        // Plan 06 Phase 4b design §7.4 (risk R6): classes used only inside
        // sendtrap/core package components would otherwise be purged from
        // the compiled CSS.
        './vendor/sendtrap/core/resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // The design tokens the package components reference
                // (§7.4): navy → bright blue.
                brand: {
                    50: '#eff5ff',
                    100: '#dbe7fe',
                    200: '#bfd3fe',
                    300: '#93b4fd',
                    400: '#6090fa',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8',
                    800: '#1e40af',
                    900: '#14235f',
                },
            },
            boxShadow: {
                glow: '0 20px 60px -15px rgba(37, 99, 235, 0.45)',
                soft: '0 10px 40px -12px rgba(15, 23, 42, 0.18)',
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(24px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                shine: {
                    '0%': { transform: 'translateX(-120%)' },
                    '60%, 100%': { transform: 'translateX(220%)' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.7s cubic-bezier(0.22, 1, 0.36, 1) both',
                shine: 'shine 3.5s ease-in-out infinite',
            },
        },
    },

    plugins: [forms, typography],
};
