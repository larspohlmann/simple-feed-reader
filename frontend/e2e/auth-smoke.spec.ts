// e2e/auth-smoke.spec.ts
import { test, expect } from '@playwright/test';

test('login page loads and offers registration', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Create account' })).toBeVisible();
});

test('a wrong password shows the backend message (real cross-origin + problem+json)', async ({ page }) => {
  await page.goto('/login');
  await page.locator('input[type=email]').fill('nobody@example.com');
  await page.locator('input[type=password]').fill('definitely-wrong-1');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page.getByText(/incorrect/i)).toBeVisible();
});

test('theme choice persists across reload', async ({ page }) => {
  await page.goto('/login');
  await page.evaluate(() => localStorage.setItem('sfr.theme', 'dark'));
  await page.reload();
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
});
