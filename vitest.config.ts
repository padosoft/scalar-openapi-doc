import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Vitest config is kept separate from vite.config.ts so the app build is not
// coupled to the test runner. `pool: 'forks'` is the Windows-safe choice
// (thread workers can hang under Herd/Windows).
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        pool: 'forks',
        setupFiles: ['./vitest.setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        css: false,
    },
});
