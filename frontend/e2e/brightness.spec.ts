import { test, expect, Page } from '@playwright/test';
import { presetLocalStorage } from './support/auth';

function mediaFilter(page: Page): Promise<string> {
  return page.evaluate(() => {
    const image = document.createElement('img');
    document.body.append(image);
    return getComputedStyle(image).filter;
  });
}

// Both checks are root-level (an <html> attribute and a global media rule),
// so the login page proves them without a sign-in.
test('a saved dark brightness step paints from the first frame and dims media', async ({
  page,
}) => {
  await presetLocalStorage(page, { 'sfr.theme': 'dark', 'sfr.brightness.dark': '-3' });
  await page.goto('/login');

  await expect(page.locator('html')).toHaveAttribute('data-brightness', '-3');
  expect(await mediaFilter(page)).toBe('brightness(0.76)');
});

test('a light step above the default is clamped before the app boots', async ({ page }) => {
  await presetLocalStorage(page, { 'sfr.theme': 'light', 'sfr.brightness.light': '3' });
  await page.goto('/login');

  await expect(page.locator('html')).toHaveAttribute('data-brightness', '0');
});

test('no saved step leaves the default render without a media filter', async ({ page }) => {
  await page.goto('/login');

  await expect(page.locator('html')).toHaveAttribute('data-brightness', '0');
  expect(await mediaFilter(page)).toBe('none');
});
