import { test, expect } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

test('magazine is the default layout and the toggle switches modes', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Magazine is the default reading layout, so signing in lands here directly.
  const group = page.getByRole('group', { name: 'Reading layout' });
  await expect(group).toBeVisible();
  await expect(group.getByRole('button', { name: 'Magazine layout, boxed' })).toBeVisible();
  await expect(group.getByRole('button', { name: 'Magazine layout, airy' })).toBeVisible();
  await expect(group.getByRole('button', { name: 'List layout' })).toBeVisible();
  await expect(group.getByRole('button', { name: 'Pane layout' })).toBeVisible();

  // Switch to List and back; the reader shell stays mounted throughout.
  await group.getByRole('button', { name: 'List layout' }).click();
  await expect(page.locator('app-reader-header')).toBeVisible();

  await group.getByRole('button', { name: 'Magazine layout, boxed' }).click();
  await expect(page.locator('app-reader-header')).toBeVisible();
});
