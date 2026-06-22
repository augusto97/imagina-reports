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
    build: {
        rollupOptions: {
            output: {
                // Split heavy vendor libraries into their own chunks so the shared
                // BlockRenderer bundle (was ~690 kB) shrinks and these cache independently.
                // STATIC chunks only (no dynamic import) — the report/portal pages still load
                // their code eagerly, so window.reportReady (the PDF wait, §10.7) is unaffected.
                // Editor-only libs (Tiptap/dnd-kit/grid) never land in the report/portal entries.
                manualChunks(id: string): string | undefined {
                    if (!id.includes('node_modules')) {
                        return undefined;
                    }

                    if (id.includes('recharts') || id.includes('/d3-') || id.includes('victory-vendor')) {
                        return 'charts';
                    }

                    if (id.includes('@tiptap') || id.includes('prosemirror')) {
                        return 'editor-richtext';
                    }

                    if (id.includes('@dnd-kit') || id.includes('react-grid-layout') || id.includes('react-resizable')) {
                        return 'editor-grid';
                    }

                    if (id.includes('framer-motion')) {
                        return 'motion';
                    }

                    if (id.includes('@tanstack')) {
                        return 'tanstack';
                    }

                    return undefined;
                },
            },
        },
    },
});
