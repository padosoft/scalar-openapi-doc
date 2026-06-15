import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@example.com');
    await page.getByLabel('Password', { exact: true }).fill('change-me');
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).toHaveURL('/dashboard');
}

test('Admin users index and create form are usable from UI', async ({ page }) => {
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
    const createdRow = page.locator('table tbody tr').filter({ hasText: email });
    await expect(createdRow).toBeVisible();
    await expect(createdRow.getByText('Playwright User')).toBeVisible();
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

    const updatedRow = page.locator('table tbody tr').filter({ hasText: email });
    await expect(updatedRow).toBeVisible();
    await expect(updatedRow.getByText('To Remove Edited')).toBeVisible();

    await updatedRow.getByRole('button', { name: 'Delete' }).click();
    const confirmButton = page.getByRole('button', { name: 'Confirm' }).first();
    await expect(confirmButton).toBeVisible();
    await confirmButton.click({ force: true });

    await expect(page).toHaveURL('/admin/users');
    await expect(page.getByText(email)).not.toBeVisible();
});
