const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
    darkMode: 'class',
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    safelist: [
        'bg-green-500',
        'bg-yellow-500',
        'bg-red-500',
        'bg-green-100',
        'bg-yellow-100',
        'bg-red-100',
        'text-green-700',
        'text-yellow-700',
        'text-red-700',
        'dark:bg-green-900/40',
        'dark:bg-yellow-900/40',
        'dark:bg-red-900/40',
        'dark:text-green-300',
        'dark:text-yellow-300',
        'dark:text-red-300',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },
    plugins: [require('@tailwindcss/forms'), require('@tailwindcss/typography')],
};
