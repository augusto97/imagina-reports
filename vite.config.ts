import { fileURLToPath, URL } from 'node:url';

import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// Two first-party SPAs share one build (CLAUDE.md §11): the admin panel and the
// interactive client portal. Both are produced in CI so the server never runs Node.
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/admin/main.tsx',
                'resources/js/portal/main.tsx',
                'resources/js/report/main.tsx',
            ],
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            '@admin': fileURLToPath(new URL('./resources/js/admin', import.meta.url)),
            '@portal': fileURLToPath(new URL('./resources/js/portal', import.meta.url)),
            '@shared': fileURLToPath(new URL('./resources/js/shared', import.meta.url)),
        },
    },
});
