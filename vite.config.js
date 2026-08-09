import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import fs from 'node:fs';

// blotter.css is the v2.2 interface bundle: Tailwind v4 plus this project's
// own ledger primitives. It is a separate entry from app.css on purpose.
// Only layouts/blotter.blade.php loads it, so the pages still running on the
// legacy shell keep their own cascade untouched until they are migrated and
// Tailwind's preflight never lands on a page that did not ask for it.
const candidateInputs = [
    'resources/css/blotter.css',
    'resources/css/app.css',
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
