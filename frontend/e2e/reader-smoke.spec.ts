import { test, expect } from '@playwright/test';
import { signInAsAdmin } from './support/auth';

test('reader shell renders after signing in, and Add feed opens a dialog', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // --- The reader shell renders: header + sidebar. ---
  // The header is proven by its own controls (Add feed + the reading-layout
  // toggle), which live only in the reader header.
  await expect(page.locator('app-reader-header')).toBeVisible();
  await expect(page.getByRole('group', { name: 'Reading layout' })).toBeVisible();

  // The sidebar's always-present "All items" nav is the stable anchor — it does
  // not depend on any subscription existing.
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.getByRole('link', { name: 'All items' })).toBeVisible();

  // --- The Add-feed dialog opens with a URL field. ---
  // No live feed is fetched here: this asserts only that the dialog surface
  // renders, so the smoke never depends on a reachable remote feed.
  await page.getByRole('button', { name: 'Add feed' }).click();

  const dialog = page.getByRole('dialog', { name: 'Add a feed' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('textbox', { name: 'Feed or site URL' })).toBeVisible();

  // Closing the dialog dismisses it — round-trips open/close with no network.
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();
});

test('Add feed warns, and offers no subscribe, for a site that cannot be scraped', async ({
  page,
}) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // The scrape-success path can't run over live HTTP in e2e — UrlGuard (SSRF
  // protection) rightly refuses locally served fixture pages, and real target
  // sites are non-deterministic. The warning path IS deterministic: a reserved
  // `.example` host never resolves, so discovery reports 'unreachable' and the
  // dialog must warn instead of offering a dead-end Subscribe. (Scrape-success
  // is covered end-to-end at the backend functional layer with a stub fetcher.)
  await page.getByRole('button', { name: 'Add feed' }).click();
  const dialog = page.getByRole('dialog', { name: 'Add a feed' });
  const field = dialog.getByRole('textbox', { name: 'Feed or site URL' });
  // The dialog autofocuses the field (cdkFocusInitial); fill only after that
  // initial focus has settled, or the value can race the focus and be lost.
  await expect(field).toBeFocused();
  await field.fill('https://no-such-host.sfr-e2e.example/');
  await expect(field).toHaveValue('https://no-such-host.sfr-e2e.example/');
  await dialog.getByRole('button', { name: 'Add' }).click();

  // A warning appears; no Subscribe control is offered anywhere in the dialog.
  await expect(dialog.getByRole('alert')).toContainText("couldn't be reached", { timeout: 30_000 });
  await expect(dialog.getByRole('button', { name: 'Subscribe' })).toHaveCount(0);

  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();
});
