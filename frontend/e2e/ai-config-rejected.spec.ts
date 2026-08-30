// e2e/ai-config-rejected.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-kicker-one-line.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const ENDPOINT = 'https://api.example.test/v1';
const OVER_LONG_KEY = 'a'.repeat(513);
const SERVER_MESSAGE = 'This value is too long. It should have 512 characters or less.';

/**
 * One saved configuration and a rejected add. Both are stubbed so the spec
 * owns every value it asserts on: nothing is created, nothing seeded is read,
 * and no outbound call reaches a real provider.
 *
 * The list holds one row on purpose. With an empty list the group renders no
 * `.configs` list at all, and "no banner on the list" would assert against an
 * element that does not exist — a green test proving nothing. One row makes
 * the negative real.
 *
 * Matched on the pathname, so `/api/me/ai` and `/api/me/ai/configs` do not
 * catch each other.
 */
const EXISTING = {
  id: 1,
  name: 'Existing provider',
  baseUrl: 'https://existing.example.test/v1',
  apiKeyHint: '9876',
  model: 'some-model',
  ready: true,
  active: true,
  suppressReasoning: true,
  batchConcurrency: 1,
};

async function stubAi(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me/ai',
    (route) => route.fulfill({ status: 200, json: { configs: [EXISTING], activeId: EXISTING.id } }),
  );

  await page.route(
    (url) => url.pathname === '/api/me/ai/configs',
    (route) => {
      if (route.request().method() !== 'POST') return route.fallback();
      return route.fulfill({
        status: 422,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'validation_error',
          title: 'Validation failed',
          status: 422,
          detail: 'One or more fields are invalid.',
          errors: { apiKey: [SERVER_MESSAGE] },
        }),
      });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubAi(page);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/**
 * The three faults of #415 in one pass: the banner must name the field with
 * the server's own sentence, it must sit under the form that failed, and the
 * typed values must survive.
 */
test('a rejected configuration keeps the typed values and names the field', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.goto('/settings/ai');

  // The stubbed configuration is active and ready, so the provider group folds
  // to its one-line summary. "Manage" unfolds the configuration list and the
  // add form beneath it — #547 replaced the cards with grouped disclosures.
  await page.getByRole('button', { name: 'Manage' }).click();

  // The add form is a collapsed disclosure; its summary opens it.
  const addForm = page.locator('app-disclosure').filter({ has: page.locator('.add-config') });
  await addForm.locator('summary').click();

  await addForm.locator('input[type=url]').fill(ENDPOINT);
  await addForm.locator('input[type=password]').fill(OVER_LONG_KEY);
  await addForm.locator('.add-config button').click();

  // 1. the server's sentence, naming the field — not "Something went wrong".
  // 2. under the add form, not on the configuration list above it.
  await expect(addForm.locator('app-error-banner')).toHaveText(`API key: ${SERVER_MESSAGE}`);

  // `.conn-list` holds the saved configurations and ends before the add form,
  // so a banner found inside it is one that leaked onto the list.
  const list = page.locator('.conn-list');
  await expect(list.locator('.configs .config-row')).toHaveCount(1); // else the next line proves nothing
  await expect(list.locator('app-error-banner')).toHaveCount(0);

  // 3. the values survive the rejection.
  await expect(addForm.locator('input[type=url]')).toHaveValue(ENDPOINT);
  await expect(addForm.locator('input[type=password]')).toHaveValue(OVER_LONG_KEY);
});
