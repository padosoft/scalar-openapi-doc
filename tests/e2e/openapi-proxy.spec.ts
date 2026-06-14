import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/login');
    await page.getByLabel(/email/i).fill('admin@example.com');
    await page.getByLabel('Password', { exact: true }).fill('change-me');
    await page.getByRole('button', { name: /log in/i }).click();
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
