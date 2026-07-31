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
  await expect(page).toHaveURL(/\/settings\/tags$/);
  await expect(page.getByRole('heading', { name: 'Settings' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Tags' })).toBeVisible();

  // The rail navigates between sections.
  const nav = page.getByRole('navigation', { name: 'Settings' });
  await nav.getByRole('link', { name: 'Import & export' }).click();
  await expect(page).toHaveURL(/\/settings\/import$/);
  await expect(page.getByRole('heading', { name: 'Import & export' })).toBeVisible();

  // The New-tag dialog still opens from the tags section (no network write).
  await nav.getByRole('link', { name: 'Tags' }).click();
  await page.getByRole('button', { name: 'New tag' }).click();
  const dialog = page.getByRole('dialog', { name: 'New tag' });
  await expect(dialog).toBeVisible();
  await dialog.getByRole('button', { name: 'Cancel' }).click();
  await expect(dialog).toBeHidden();

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

/**
 * Deletes a tag by its current visible name through the real UI flow (Delete
 * button, then the destructive confirm dialog) so a run of this test never
 * leaves fixture debris behind for the next one — stray "…edited" tags from
 * a prior run once collided with the `/edit/i` button-name match below.
 */
async function deleteTagByName(page: Page, name: string): Promise<void> {
  const row = page.locator('.tag').filter({ has: page.locator('.name', { hasText: name }) });
  if ((await row.count()) === 0) return;
  await row
    .first()
    .getByRole('button', { name: /^(delete|löschen)$/i })
    .click();
  const confirmDialog = page.getByRole('alertdialog');
  await confirmDialog.getByRole('button', { name: /^(delete|löschen)$/i }).click();
  await expect(confirmDialog).toBeHidden();
}

test('a tag can be reordered and renamed from settings', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/tags');

  const rows = page.locator('.tag');
  // The section loads before it has anything to show — wait past the
  // skeleton for either the empty state or the list rather than assuming
  // rows exist yet.
  await expect(page.locator('app-skeleton')).toHaveCount(0);

  // The seeded fixture is not guaranteed to carry two tags (it may carry
  // none at all). Create them through the real "New tag" dialog rather than
  // weakening the assertions below to fit whatever the fixture happens to
  // hold. Names created here are deleted again in `finally` below so repeat
  // runs never accumulate fixture debris.
  const ownedNames: string[] = [];
  while ((await rows.count()) < 2) {
    const name = `E2E reorder tag ${Date.now()}-${ownedNames.length}`;
    await page.getByRole('button', { name: 'New tag' }).click();
    const newDialog = page.getByRole('dialog', { name: 'New tag' });
    await newDialog.locator('#tag-name').fill(name);
    await newDialog.getByRole('button', { name: 'Save' }).click();
    await expect(newDialog).toBeHidden();
    // Wait for this exact tag to render before the next count check — the
    // dialog closing does not imply the list has re-rendered yet, and
    // without this wait the loop outraces the store update and keeps
    // creating tags long past two.
    await expect(
      page.locator('.tag').filter({ has: page.locator('.name', { hasText: name }) }),
    ).toBeVisible();
    ownedNames.push(name);
  }

  try {
    await expect(rows.first()).toBeVisible();
    const firstName = await rows.first().locator('.name').innerText();
    const secondName = await rows.nth(1).locator('.name').innerText();

    // Reorder: drag the first row's handle below the second. CDK drag only
    // starts once the pointer has actually moved past a threshold, so this
    // steps the mouse rather than jumping straight from hover to hover.
    const handleBox = await rows.first().locator('.drag-handle').boundingBox();
    const targetBox = await rows.nth(1).boundingBox();
    if (!handleBox || !targetBox) throw new Error('drag geometry unavailable');
    const handleX = handleBox.x + handleBox.width / 2;
    const handleY = handleBox.y + handleBox.height / 2;
    await page.mouse.move(handleX, handleY);
    await page.mouse.down();
    await page.mouse.move(handleX, handleY + 10, { steps: 5 });
    await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height - 4, {
      steps: 10,
    });
    await page.mouse.up();

    await expect(rows.first().locator('.name')).not.toHaveText(firstName);
    await expect(rows.first().locator('.name')).toHaveText(secondName);

    // The new order is the server's, not just a local list splice — reload
    // and check it stuck.
    await page.reload();
    const rowsAfterReload = page.locator('.tag');
    await expect(rowsAfterReload.first().locator('.name')).toHaveText(secondName);

    // Inline edit: the editor opens on the row, not in a dialog. Anchored so
    // it never matches the drag handle's "Reorder <name>" label when <name>
    // itself contains the substring "edit".
    await rowsAfterReload
      .first()
      .getByRole('button', { name: /^(edit|bearbeiten)$/i })
      .click();
    await expect(rowsAfterReload.first().locator('.editor')).toBeVisible();
    await expect(page.locator('.app-dialog')).toHaveCount(0);

    // Actually change the name, not merely open the editor, and confirm the
    // save both updates the row and survives a reload.
    const renamedTo = `${secondName} edited`;
    await rowsAfterReload.first().locator('.editor app-field input').fill(renamedTo);
    await rowsAfterReload
      .first()
      .getByRole('button', { name: /^(save|speichern)$/i })
      .click();
    await expect(rowsAfterReload.first().locator('.editor')).toBeHidden();
    await expect(rowsAfterReload.first().locator('.name')).toHaveText(renamedTo);

    await page.reload();
    await expect(page.locator('.tag').first().locator('.name')).toHaveText(renamedTo);

    // Track the tag under its final (renamed) name for cleanup below.
    if (ownedNames.includes(secondName)) {
      ownedNames[ownedNames.indexOf(secondName)] = renamedTo;
    }
  } finally {
    for (const name of ownedNames) {
      await deleteTagByName(page, name);
    }
  }
});
