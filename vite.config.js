import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import fs from 'node:fs';

const candidateInputs = [
    'resources/css/blotter.css',
    'extensions/installer/assets/install.css',
    'resources/js/app.js',
];

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: candidateInputs.filter((p) => fs.existsSync(p)),
            refresh: true,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
