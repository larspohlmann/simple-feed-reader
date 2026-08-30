// e2e/settings-admin-smoke.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin — the same fixture the backend ReaderJourneyE2eTest
// authenticates as (`bin/console app:e2e:seed-admin`, run by `bin/e2e.sh`).
// Overridable so this smoke can point at another environment without edits.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/**
 * Sign in through the real login form — the same selectors and cross-origin
 * flow `auth-smoke.spec.ts` drives — with the seeded admin credentials, then
 * wait for the reader shell to mount. Returns `false` (rather than failing)
 * when the credentials are rejected, so a stack without the seeded admin — or
 * a rate-limited login — skips cleanly instead of flaking. Mirrors the backend
 * real-feed e2e convention: an unavailable precondition is skipped, not failed.
 */
async function signInAsAdmin(page: Page): Promise<boolean> {
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  // Success mounts the reader sidebar; failure surfaces the login error alert.
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

test('settings shell navigates sections; admin pages live inside it', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // Open Settings from the account menu. On the desktop viewport the bare
  // /settings url forwards to the first section.
  await page.getByRole('button', { name: 'Account' }).click();
  await page.getByRole('menuitem', { name: 'Settings' }).click();
  await expect(page).toHaveURL(/\/settings\/organise$/);
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Organise' })).toBeVisible();

  // The rail navigates between sections.
  const nav = page.getByRole('navigation', { name: 'Settings' });
  await nav.getByRole('link', { name: 'Import & export' }).click();
  await expect(page).toHaveURL(/\/settings\/import$/);
  await expect(page.getByRole('heading', { name: 'Import & export' })).toBeVisible();

  // The New-tag dialog still opens from Organise (no network write).
  await nav.getByRole('link', { name: 'Organise' }).click();
  await page.getByRole('button', { name: 'New tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'New tag' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();

  // The retired Tags url forwards to Organise, so an old bookmark still lands (#714).
  await page.goto('/settings/tags');
  await expect(page).toHaveURL(/\/settings\/organise$/);

  // Pre-#180 admin urls redirect into the shell.
  await page.goto('/admin/users');
  await expect(page).toHaveURL(/\/settings\/admin\/users$/);
  await expect(page.getByRole('heading', { name: 'Users' })).toBeVisible();
  await expect(page.getByRole('group', { name: 'Filter by status' })).toBeVisible();

  // The catalog renders read-only rows and its category dialog opens (no write).
  await page.goto('/settings/admin/catalog');
  await expect(page.getByTestId('add-category')).toBeVisible();
  await page.getByTestId('add-category').click();
  const categoryDialog = page.getByRole('dialog', { name: 'New category' });
  await expect(categoryDialog).toBeVisible();
  await categoryDialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(categoryDialog).toBeHidden();

  // The admin can open one account's detail page from the list.
  await page.goto('/settings/admin/users');
  const firstUser = page.locator('a[href^="/settings/admin/users/"]').first();
  await expect(firstUser).toBeVisible();
  await firstUser.click();
  await expect(page).toHaveURL(/\/settings\/admin\/users\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Feeds' })).toBeVisible();
});

/** Every tag group on Organise, untagged excluded: only a real tag offers the
 *  edit button, and only a real tag can be reordered or renamed. */
function tagGroups(page: Page) {
  return page
    .locator('app-organise-tag-group')
    .filter({ has: page.getByRole('button', { name: 'Edit tag' }) });
}

function tagGroupByName(page: Page, name: string) {
  return tagGroups(page)
    .filter({ has: page.locator('.head .name', { hasText: name }) })
    .first();
}

async function createTag(page: Page, name: string): Promise<void> {
  await page.getByRole('button', { name: 'New tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'New tag' });
  await dialog.locator('#tag-name').fill(name);
  await dialog.getByRole('button', { name: 'Save' }).click();
  await expect(dialog).toBeHidden();
  await expect(tagGroupByName(page, name)).toBeVisible();
}

/** Renames one owned tag through the shared tag dialog — the one editor for a
 *  tag now that the Tags page is gone (#714). */
async function renameTag(page: Page, from: string, to: string): Promise<void> {
  const group = tagGroupByName(page, from);
  await group.getByRole('button', { name: 'Edit tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'Edit tag' });
  await dialog.locator('#tag-name').fill(to);
  await dialog.getByRole('button', { name: 'Save' }).click();
  await expect(dialog).toBeHidden();
  await expect(tagGroupByName(page, to)).toBeVisible();
}

async function expectTagBefore(page: Page, before: string, after: string): Promise<void> {
  await expect
    .poll(async () => {
      const names = await tagGroups(page).locator('.head .name').allTextContents();
      const beforeIndex = names.indexOf(before);
      const afterIndex = names.indexOf(after);
      return beforeIndex >= 0 && afterIndex >= 0 && beforeIndex < afterIndex;
    })
    .toBe(true);
}

async function moveTagDown(page: Page, name: string): Promise<void> {
  const response = page.waitForResponse(
    (candidate) =>
      candidate.request().method() === 'PATCH' &&
      new URL(candidate.url()).pathname.endsWith('/api/tags/reorder') &&
      candidate.ok(),
  );
  await tagGroupByName(page, name).locator('[data-test="tag-down"]').click();
  await response;
}

/**
 * Deletes a tag by its current visible name through the real UI flow (the
 * group's Delete button, then the destructive confirm dialog) so a run never
 * leaves fixture debris behind for the next one.
 */
async function deleteTagByName(page: Page, name: string): Promise<void> {
  const group = tagGroupByName(page, name);
  if ((await group.count()) === 0) return;
  await group.getByRole('button', { name: 'Delete tag' }).click();
  const confirmDialog = page.getByRole('alertdialog');
  const response = page.waitForResponse(
    (candidate) =>
      candidate.request().method() === 'DELETE' &&
      /\/api\/tags\/\d+$/.test(new URL(candidate.url()).pathname) &&
      candidate.ok(),
  );
  await confirmDialog.getByRole('button', { name: /^(delete|löschen)$/i }).click();
  await response;
  await expect(confirmDialog).toBeHidden();
  await expect(group).toHaveCount(0);
}

test('a tag can be reordered and renamed from Organise', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/organise');

  // The page loads before it has anything to show — wait past the skeleton
  // rather than assuming groups exist yet.
  await expect(page.locator('app-skeleton')).toHaveCount(0);

  // Always create the two tags this test manipulates. Existing account tags
  // belong to other tests or the developer; their count and order are not test
  // data and must not decide which records this flow edits.
  const run = Date.now();
  const firstName = `E2E reorder tag ${run}-0`;
  const secondName = `E2E reorder tag ${run}-1`;
  const renamedTo = `E2E renamed ${run}`;

  try {
    await createTag(page, firstName);
    await createTag(page, secondName);

    // Reorder through the header's own arrow, not a drag: the arrows are the
    // keyboard-reachable path and they exercise the same reorder request.
    await moveTagDown(page, firstName);
    await expectTagBefore(page, secondName, firstName);

    // The new order is the server's, not just a local list splice — reload
    // and check it stuck.
    await page.reload();
    await expectTagBefore(page, secondName, firstName);

    // Actually change the name, not merely open the dialog, and confirm the
    // save both updates the header and survives a reload.
    await renameTag(page, secondName, renamedTo);
    await page.reload();
    await expect(tagGroupByName(page, renamedTo)).toBeVisible();

    await renameTag(page, renamedTo, secondName);

    // Restore the owned pair's order before cleanup, proving the reverse write
    // as well without touching any pre-existing tag.
    await moveTagDown(page, secondName);
    await expectTagBefore(page, firstName, secondName);
  } finally {
    let cleanupFailure: unknown;
    for (const name of [firstName, secondName, renamedTo]) {
      try {
        await deleteTagByName(page, name);
      } catch (error) {
        cleanupFailure ??= error;
      }
    }
    if (cleanupFailure) throw cleanupFailure;
  }
});
