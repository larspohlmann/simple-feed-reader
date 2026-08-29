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
  await page.getByRole('button', { name: 'Sign in' }).click();

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

/** Renames the first tag group through the shared tag dialog — the one editor
 *  for a tag now that the Tags page is gone (#714). */
async function renameFirstGroup(page: Page, to: string): Promise<void> {
  const group = tagGroups(page).first();
  await group.getByRole('button', { name: 'Edit tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'Edit tag' });
  await dialog.locator('#tag-name').fill(to);
  await dialog.getByRole('button', { name: 'Save' }).click();
  await expect(dialog).toBeHidden();
  await expect(group.locator('.head .name')).toHaveText(to);
}

/**
 * Deletes a tag by its current visible name through the real UI flow (the
 * group's Delete button, then the destructive confirm dialog) so a run never
 * leaves fixture debris behind for the next one.
 */
async function deleteTagByName(page: Page, name: string): Promise<void> {
  const group = tagGroups(page).filter({ has: page.locator('.head .name', { hasText: name }) });
  if ((await group.count()) === 0) return;
  await group.first().getByRole('button', { name: 'Delete tag' }).click();
  const confirmDialog = page.getByRole('alertdialog');
  await confirmDialog.getByRole('button', { name: /^(delete|löschen)$/i }).click();
  await expect(confirmDialog).toBeHidden();
}

test('a tag can be reordered and renamed from Organise', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/organise');

  const groups = tagGroups(page);
  // The page loads before it has anything to show — wait past the skeleton
  // rather than assuming groups exist yet.
  await expect(page.locator('app-skeleton')).toHaveCount(0);

  // The seeded fixture is not guaranteed to carry two tags (it may carry none
  // at all). Create them through the real "New tag" dialog rather than
  // weakening the assertions below to fit whatever the fixture happens to
  // hold. Names created here are deleted again in `finally` so repeat runs
  // never accumulate fixture debris.
  const ownedNames: string[] = [];
  while ((await groups.count()) < 2) {
    const name = `E2E reorder tag ${Date.now()}-${ownedNames.length}`;
    await page.getByRole('button', { name: 'New tag' }).click();
    const newDialog = page.getByRole('dialog', { name: 'New tag' });
    await newDialog.locator('#tag-name').fill(name);
    await newDialog.getByRole('button', { name: 'Save' }).click();
    await expect(newDialog).toBeHidden();
    // Wait for this exact tag to render before the next count check — the
    // dialog closing does not imply the tree has re-rendered yet, and without
    // this wait the loop outraces the store update and keeps creating tags.
    await expect(
      tagGroups(page).filter({ has: page.locator('.head .name', { hasText: name }) }),
    ).toBeVisible();
    ownedNames.push(name);
  }

  try {
    await expect(groups.first()).toBeVisible();
    const firstName = await groups.first().locator('.head .name').innerText();
    const secondName = await groups.nth(1).locator('.head .name').innerText();

    // Reorder through the header's own arrow, not a drag: the arrows are the
    // keyboard-reachable path and they exercise the same reorder request.
    await groups.first().locator('[data-test="tag-down"]').click();

    await expect(groups.first().locator('.head .name')).toHaveText(secondName);

    // The new order is the server's, not just a local list splice — reload
    // and check it stuck.
    await page.reload();
    await expect(tagGroups(page).first().locator('.head .name')).toHaveText(secondName);

    // Actually change the name, not merely open the dialog, and confirm the
    // save both updates the header and survives a reload.
    const renamedTo = `E2E renamed ${Date.now()}`;
    await renameFirstGroup(page, renamedTo);
    await page.reload();
    await expect(tagGroups(page).first().locator('.head .name')).toHaveText(renamedTo);

    // Put the original name back. The group here may be a fixture tag this
    // test does not own and so never deletes; leaving it renamed made every
    // run rename an already-renamed tag, until the name hit the server's
    // 100-char cap and the save came back truncated.
    await renameFirstGroup(page, secondName);

    // And put the order back, for the same reason.
    await tagGroups(page).first().locator('[data-test="tag-down"]').click();
    await expect(tagGroups(page).first().locator('.head .name')).toHaveText(firstName);
  } finally {
    for (const name of ownedNames) {
      await deleteTagByName(page, name);
    }
  }
});
