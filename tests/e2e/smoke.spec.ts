import { expect, test } from '@playwright/test';

// Toolchain smoke test: proves the Playwright + `php artisan serve` pipeline
// works end-to-end. Feature-specific E2E (auth flows, admin grids, the
// per-user Scalar sidebar) lands with each feature in T5–T8.
test('login page renders the credentials form', async ({ page }) => {
    await page.goto('/login');

    await expect(page.getByLabel(/email/i)).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: /log in/i })).toBeVisible();
});
