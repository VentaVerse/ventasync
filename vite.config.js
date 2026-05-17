import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import fs from 'node:fs';

const candidateInputs = [
    'resources/css/app.css',
    'extensions/installer/assets/install.css',
    'resources/js/app.js',
];

export default defineConfig({
    plugins: [
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
