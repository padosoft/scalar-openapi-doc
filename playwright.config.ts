import { defineConfig, devices } from '@playwright/test';

const PORT = 8123;
const baseURL = `http://127.0.0.1:${PORT}`;

// The webServer inherits the ambient environment, so it uses the local `.env`
// (MySQL + Redis via Herd) for local runs. CI sets DB_*/CACHE_STORE to SQLite +
// array and migrates before invoking Playwright (see T2.3 CI), and those env
// vars flow through to `php artisan serve` here.
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
    ],
    webServer: {
        command: `php artisan serve --port=${PORT}`,
        env: {
            ...process.env,
            OPENAPI_LOGIN_RATE_LIMIT_ATTEMPTS:
                process.env.E2E_LOGIN_RATE_LIMIT_ATTEMPTS
                ?? process.env.OPENAPI_LOGIN_RATE_LIMIT_ATTEMPTS
                ?? '100',
        },
        url: baseURL,
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
