import { expect, test } from '@playwright/test';
import type { Cookie, Page } from '@playwright/test';

test.describe.configure({ mode: 'serial' });

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'change-me';
let ADMIN_SESSION_COOKIES: Cookie[] | null = null;

async function loginAsAdmin(page: Page): Promise<void> {
    if (ADMIN_SESSION_COOKIES !== null) {
        await page.context().addCookies(ADMIN_SESSION_COOKIES);
        await page.goto('/dashboard');

        if (!page.url().includes('/dashboard')) {
            await page.context().clearCookies();
        } else {
            return;
        }
    }

    await page.goto('/login');
    await page.getByLabel(/email/i).fill(ADMIN_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASSWORD);
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).toHaveURL('/dashboard');
    ADMIN_SESSION_COOKIES = await page.context().cookies();
}

test('Admin users index and create form are usable from UI', async ({
    page,
}) => {
    await loginAsAdmin(page);
    await page.goto('/admin/users');
    await expect(page).toHaveURL('/admin/users');

    await page.goto('/admin/users/create');
    await expect(page).toHaveURL('/admin/users/create');

    const email = `playwright-ui-${Date.now()}@example.com`;
    await page.getByLabel('Name').fill('Playwright User');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('Password123!');
    await page.getByLabel('Role').selectOption('user');

    await page.getByRole('button', { name: 'Create user' }).click();
    await expect(page).toHaveURL('/admin/users');
    const createdRow = page
        .locator('table tbody tr')
        .filter({ hasText: email });
    await expect(createdRow).toBeVisible();
    await expect(createdRow.getByText('Playwright User')).toBeVisible();
});

test('Admin can grant a server to a user and it persists on edit', async ({
    page,
}) => {
    await loginAsAdmin(page);

    // Create a server so the user form has a grantable option.
    const serverUrl = `https://pw-grant-${Date.now()}.example.com`;
    await page.goto('/servers/create');
    await page.getByLabel('Server URL').fill(serverUrl);
    await page.getByLabel('Description').fill('Grantable server');
    await page.getByLabel('Sort order').fill('1');
    await page.getByRole('button', { name: 'Create server' }).click();
    await expect(page).toHaveURL('/servers');

    // Create a user and grant the server via the "Granted servers" control.
    const email = `playwright-server-grant-${Date.now()}@example.com`;
    await page.goto('/admin/users/create');
    await page.getByLabel('Name').fill('Server Grantee');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('Password123!');
    await page.getByLabel('Role').selectOption('user');

    // The toggle button's accessible name comes from its associated <label>
    // ("Granted servers"); the selected-count is its inner text.
    const serversControl = page.getByRole('button', {
        name: 'Granted servers',
    });
    await expect(serversControl).toContainText('No servers selected');
    await serversControl.click();
    await page.locator('label').filter({ hasText: serverUrl }).click();
    await expect(serversControl).toContainText('1 selected');

    await page.getByRole('button', { name: 'Create user' }).click();
    await expect(page).toHaveURL('/admin/users');

    // The grant survives a round-trip: the edit form pre-selects one server.
    const row = page.locator('table tbody tr').filter({ hasText: email });
    await row.getByRole('link', { name: 'Edit' }).click();
    await expect(page).toHaveURL(new RegExp('/admin/users/\\d+/edit'));
    await expect(
        page.getByRole('button', { name: 'Granted servers' }),
    ).toContainText('1 selected');
});

test('Admin can edit then delete a user', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/admin/users');
    await expect(page).toHaveURL('/admin/users');

    const email = `playwright-delete-${Date.now()}@example.com`;
    await page.goto('/admin/users/create');

    await page.getByLabel('Name').fill('To Remove');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('Password123!');
    await page.getByLabel('Role').selectOption('user');
    await page.getByRole('button', { name: 'Create user' }).click();

    const row = page.locator('table tbody tr').filter({ hasText: email });
    await expect(row).toBeVisible();

    await row.getByRole('link', { name: 'Edit' }).click();
    await expect(page).toHaveURL(new RegExp('/admin/users/\\d+/edit'));

    await page.getByLabel('Name').fill('To Remove Edited');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page).toHaveURL('/admin/users');

    const updatedRow = page
        .locator('table tbody tr')
        .filter({ hasText: email });
    await expect(updatedRow).toBeVisible();
    await expect(updatedRow.getByText('To Remove Edited')).toBeVisible();

    await updatedRow.getByRole('button', { name: 'Delete' }).click();
    const confirmDialog = page.getByRole('dialog', { name: 'Delete user' });
    await expect(confirmDialog).toBeVisible();
    const confirmButton = confirmDialog.getByRole('button', {
        name: 'Confirm',
    });
    await expect(confirmButton).toBeVisible();
    await confirmButton.click({ force: true });

    await expect(page).toHaveURL('/admin/users');
    await expect(page.getByText(email)).not.toBeVisible();
});
