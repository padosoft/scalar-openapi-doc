import { fileURLToPath } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Vitest config is kept separate from vite.config.ts so the app build is not
// coupled to the test runner. `pool: 'forks'` is the Windows-safe choice
// (thread workers can hang under Herd/Windows). `__dirname` is undefined under
// "type": "module", so resolve the alias from import.meta.url instead.
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
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
