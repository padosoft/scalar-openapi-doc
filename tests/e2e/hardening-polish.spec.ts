import { expect, test } from '@playwright/test';
import type { Cookie, Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'change-me';
let ADMIN_SESSION_COOKIES: Cookie[] | null = null;

async function loginAs(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).toHaveURL('/dashboard');
}

async function loginAsAdmin(page: Page): Promise<void> {
    if (ADMIN_SESSION_COOKIES !== null) {
        await page.context().addCookies(ADMIN_SESSION_COOKIES);
        await page.goto('/dashboard');

        if (!page.url().includes('/dashboard')) {
            await loginAs(page, ADMIN_EMAIL, ADMIN_PASSWORD);
            ADMIN_SESSION_COOKIES = await page.context().cookies();
        }

        return;
    }

    await loginAs(page, ADMIN_EMAIL, ADMIN_PASSWORD);
    ADMIN_SESSION_COOKIES = await page.context().cookies();
}

async function loginAsUser(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await loginAs(page, email, password);
}

async function createViewerUser(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/admin/users/create');
    await page.getByLabel('Name').fill('Viewer User');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(password);
    await page.getByLabel('Role').selectOption('user');

    await page.locator('form').evaluate((form) => {
        form.requestSubmit();
    });

    await expect(page).toHaveURL('/admin/users');
}

async function resetAuthSession(page: Page): Promise<void> {
    await page.context().clearCookies();
    await page.evaluate(() => {
        localStorage.clear();
        sessionStorage.clear();
    });
}

test('Viewer route matrix is enforced and admin sidebar links are hidden', async ({
    page,
}) => {
    const viewerEmail = `viewer-${Date.now()}@example.com`;

    await loginAsAdmin(page);
    await createViewerUser(page, viewerEmail, 'Password123!');
    await resetAuthSession(page);

    await loginAsUser(page, viewerEmail, 'Password123!');
    await expect(
        page.getByRole('link', { name: 'API Reference' }),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: 'Users' })).toBeHidden();
    await expect(page.getByRole('link', { name: 'Servers' })).toBeHidden();
    await expect(page.getByRole('link', { name: 'Auth Logs' })).toBeHidden();
    await expect(
        page.getByRole('link', { name: 'OpenAPI Cache' }),
    ).toBeHidden();

    expect((await page.request.get('/admin/users')).status()).toBe(403);
    expect((await page.request.get('/admin/users/create')).status()).toBe(403);
    expect((await page.request.get('/servers')).status()).toBe(403);
    expect((await page.request.get('/servers/create')).status()).toBe(403);
    expect((await page.request.get('/auth-logs')).status()).toBe(403);
    expect((await page.request.get('/openapi-cache')).status()).toBe(403);
});

test('Confirm dialog can be dismissed with Escape and keeps keyboard focus behavior stable', async ({
    page,
}) => {
    const userEmail = `admin-delete-${Date.now()}@example.com`;

    await loginAsAdmin(page);
    await createViewerUser(page, userEmail, 'Password123!');
    await page.goto('/admin/users');

    const row = page.locator('table tbody tr').filter({ hasText: userEmail });
    await expect(row).toBeVisible();

    await row.getByRole('button', { name: 'Delete' }).click();
    const dialog = page.getByRole('dialog', { name: 'Delete user' });
    await expect(dialog).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(row.getByRole('button', { name: 'Delete' })).toBeFocused();
});

test('Appearance settings toggles theme classes in both directions', async ({
    page,
}) => {
    await loginAsAdmin(page);
    await page.goto('/settings/appearance');

    await page.getByRole('button', { name: 'Dark' }).click();
    expect(
        await page.evaluate(() =>
            document.documentElement.classList.contains('dark'),
        ),
    ).toBe(true);

    await page.getByRole('button', { name: 'Light' }).click();
    expect(
        await page.evaluate(() =>
            document.documentElement.classList.contains('dark'),
        ),
    ).toBe(false);
});

test('Responsive layout and empty states remain usable', async ({ page }) => {
    await loginAsAdmin(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/dashboard');

    const hasHorizontalOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth > window.innerWidth + 1,
    );
    expect(hasHorizontalOverflow).toBe(false);

    await page.goto('/auth-logs');
    await page.getByLabel('Email').fill(`nope-${Date.now()}@example.com`);
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(
        page.getByText('No authentication events match these filters.'),
    ).toBeVisible();
});

test('Cache clear error state is surfaced to the user', async ({ page }) => {
    await loginAsAdmin(page);

    await page.route('**/openapi-cache', async (route) => {
        if (route.request().method() === 'DELETE') {
            await route.fulfill({
                status: 500,
                contentType: 'application/json',
                body: JSON.stringify({ error: 'boom' }),
            });

            return;
        }

        await route.continue();
    });

    await page.goto('/openapi-cache');
    await page.getByRole('button', { name: 'Flush cache' }).click();
    await expect(
        page.getByText(/Failed to clear cache|Unable to find CSRF token/),
    ).toBeVisible();
});

test('State-changing requests reject invalid CSRF tokens', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/users');

    const badTokenPayload = {
        name: 'CSRF user',
        email: `csrf-${Date.now()}@example.com`,
        password: 'Password123!',
        role: 'user',
        grants: {
            tags: [],
            endpoints: [],
        },
    };

    const createResponse = await page.request.post('/admin/users', {
        headers: {
            'X-CSRF-TOKEN': 'invalid-csrf-token',
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        data: JSON.stringify(badTokenPayload),
        failOnStatusCode: false,
    });
    expect(createResponse.status()).toBe(419);

    const cacheResponse = await page.request.delete('/api-docs/flush-cache', {
        headers: {
            'X-CSRF-TOKEN': 'invalid-csrf-token',
            Accept: 'application/json',
        },
        failOnStatusCode: false,
    });
    expect(cacheResponse.status()).toBe(419);
});
