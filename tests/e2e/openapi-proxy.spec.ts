import { expect, test } from '@playwright/test';
import type { Cookie, Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const ADMIN_EMAIL = 'admin@example.com';
const ADMIN_PASSWORD = 'change-me';
let ADMIN_SESSION_COOKIES: Cookie[] | null = null;

async function loginAsAdmin(page: Page): Promise<void> {
    if (ADMIN_SESSION_COOKIES !== null) {
        await page.context().addCookies(ADMIN_SESSION_COOKIES);
        await page.goto('/dashboard');

        if (page.url().includes('/dashboard')) {
            return;
        }

        await page.context().clearCookies();
    }

    await page.goto('/login');
    await page.getByLabel(/email/i).fill(ADMIN_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASSWORD);
    await page.getByRole('button', { name: /log in/i }).click();
    ADMIN_SESSION_COOKIES = await page.context().cookies();
    await expect(page).toHaveURL('/dashboard');
}

async function loginAsUser(page: Page, email: string, password: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: /log in/i }).click();
}

test('Scalar reference page is protected behind authentication', async ({ page }) => {
    await page.goto('/scalar');

    await expect(page).toHaveURL(/\/login/);
});

test('Sidebar shows API Reference only for users with Scalar access', async ({ page }) => {
    await loginAsAdmin(page);
    await expect(page).toHaveURL('/dashboard');

    const apiReferenceLink = page.getByRole('link', { name: 'API Reference' });
    await expect(apiReferenceLink).toBeVisible();
    await expect(apiReferenceLink).toHaveAttribute('href', '/scalar');
    // Opens the docs in a new tab, keeping the portal open in the current one.
    await expect(apiReferenceLink).toHaveAttribute('target', '_blank');
    await expect(apiReferenceLink).toHaveAttribute(
        'rel',
        'noopener noreferrer',
    );
});

test('API Reference link opens the real Scalar page in a new tab', async ({
    page,
    context,
}) => {
    await loginAsAdmin(page);
    await expect(page).toHaveURL('/dashboard');

    // target=_blank opens a new tab; capture it via the context's page event.
    const [scalarPage] = await Promise.all([
        context.waitForEvent('page'),
        page.getByRole('link', { name: 'API Reference' }).click(),
    ]);
    await scalarPage.waitForLoadState();

    // The original portal tab stays on /dashboard.
    await expect(page).toHaveURL('/dashboard');

    // The new tab loads the genuine Scalar page on the app's own origin: its
    // CDN bootstrap script is present and no Inertia error-modal iframe exists.
    await expect(scalarPage).toHaveURL(/\/scalar$/);
    await expect(
        scalarPage.locator('script[src*="@scalar/api-reference"]'),
    ).toHaveCount(1);
    await expect(scalarPage.locator('iframe[srcdoc]')).toHaveCount(0);
});

test('Admin users area is available and form loads', async ({ page }) => {
    await loginAsAdmin(page);

    const adminUsersIndex = await page.request.get('/admin/users');
    const adminUsersCreate = await page.request.get('/admin/users/create');

    expect(adminUsersIndex.status()).toBe(200);
    expect(adminUsersCreate.status()).toBe(200);
});

test('Protected admin APIs redirect non-admin users', async ({ page }) => {
    await loginAsUser(page, 'not-a-user@example.test', 'wrong');
    await page.goto('/admin/users');
    await expect(page).toHaveURL(/\/login/);
});
