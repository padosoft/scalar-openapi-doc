import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@example.com');
    await page.getByLabel('Password', { exact: true }).fill('change-me');
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).toHaveURL('/dashboard');
}

test('Admin can manage scalar servers', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/servers');
    await expect(page).toHaveURL('/servers');

    await page.getByRole('link', { name: 'Add server' }).click();
    await expect(page).toHaveURL('/servers/create');

    const url = `https://pw-${Date.now()}.example.com`;
    await page.getByLabel('Server URL').fill(url);
    await page.getByLabel('Description').fill('Playwright server');
    await page.getByLabel('Sort order').fill('11');

    await page.getByRole('button', { name: 'Create server' }).click();
    await expect(page).toHaveURL('/servers');

    const row = page.locator('table tbody tr').filter({ hasText: url });
    await expect(row).toBeVisible();

    await row.getByRole('link', { name: 'Edit' }).click();
    await expect(page).toHaveURL(new RegExp('/servers/\\d+/edit'));

    await page.getByLabel('Sort order').fill('25');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page).toHaveURL('/servers');

    const editedRow = page.locator('table tbody tr').filter({ hasText: url });
    await expect(editedRow).toBeVisible();

    await editedRow.getByRole('button', { name: 'Delete' }).click();
    const confirmDialog = page.getByRole('dialog', { name: 'Delete server' });
    await expect(confirmDialog).toBeVisible();
    await confirmDialog.getByRole('button', { name: 'Confirm' }).click();

    await expect(page).toHaveURL('/servers');
    await expect(editedRow).not.toBeVisible();
});

test('Auth Logs page is read-only and renderable', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/auth-logs');
    await expect(page).toHaveURL('/auth-logs');

    await expect(
        page.getByRole('heading', { name: 'Authentication logs' }),
    ).toBeVisible();
    await expect(page.getByLabel('Email')).toBeVisible();

    await page.getByLabel('Email').fill('admin');
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByRole('button', { name: 'Apply' })).toBeVisible();

    await expect(page.getByRole('button', { name: 'Reset' })).toBeVisible();
    await expect(
        page.getByRole('button', { name: /delete/i }),
    ).not.toBeVisible();
});
