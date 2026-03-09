/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/modern/**/*.blade.php',
        './resources/views/layouts/modern/**/*.blade.php',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#eff6ff',
                    100: '#dbeafe',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8',
                    900: '#1e3a8a',
                },
                lazada: '#0F146D',
                shopee: '#EE4D2D',
                surface: '#ffffff',
                'surface-hover': '#f8fafc',
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
    ],
}
