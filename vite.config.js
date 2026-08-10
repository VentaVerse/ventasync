import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import fs from 'node:fs';

// blotter.css is THE interface bundle: Tailwind v4 plus this project's own
// primitives. Every layout loads it.
//
// app.css, the legacy bundle it was once a sibling of, is gone. It existed so
// pages still on the old shell kept their own cascade while they were
// migrated one at a time; by the end that migration had finished and the only
// thing still loading it was layouts/standalone (one OAuth callback page,
// whose own docblock said it wanted "the same bundle as everything else") and
// layouts/shell, which no view had extended in a long time. Both are settled,
// and the 34 stylesheets reachable only from app.css went with it.
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
