import { expect, test } from '@playwright/test';
import { aSubscription } from '../src/testing/subscription-testing';
import { stubAuthToken } from './support/auth';
import { subscriptionsJson } from './support/reader';

// #561/#1034/#1771: with many tags defined, the last tag in the edit-feed
// dialog must be reachable on a phone.
//
// On a phone the picker opens a full-screen tag sheet (#1771) whose list is the
// sheet body, the one scroller (#868: a nested scroll box is a touch trap). This
// drives the wheel from inside the list and checks the last tag clears the edge.

/** Far more rows than the sheet shows at once. */
const TAG_COUNT = 18;

/** Short enough that the sheet body must scroll. */
const PHONE = { width: 390, height: 720 };

const TAGS = Array.from({ length: TAG_COUNT }, (_, index) => ({
  id: index + 1,
  name: `Tag number ${index + 1}`,
  color: '#3b82f6',
  icon: 'label',
  position: index,
}));

const SUBSCRIPTIONS = subscriptionsJson([
  aSubscription({
    title: 'Stub Feed',
    feedUrl: 'https://example.com/feed',
    siteUrl: 'https://example.com',
  }),
]);

test.use({ viewport: PHONE });

test('the last tag stays reachable when many tags are defined', async ({ page }) => {
  await stubAuthToken(page);
  // GET only: the dialog's Save issues PATCH /api/subscriptions/{id}, and
  // answering that with the list payload would be a trap for the next
  // assertion added here.
  const readOnly = (json: unknown) => (route: import('@playwright/test').Route) =>
    route.request().method() === 'GET' ? route.fulfill({ json }) : route.fallback();

  await page.route('**/api/tags**', readOnly({ tags: TAGS }));
  await page.route('**/api/subscriptions**', readOnly(SUBSCRIPTIONS));
  await page.route('**/api/entries**', readOnly({ entries: [], total: 0, page: 1, perPage: 20 }));
  // An empty address keeps the header avatar from reaching gravatar.com — the
  // one request in this spec that would otherwise leave the machine.
  await page.route('**/api/me**', readOnly({ id: 1, email: '', roles: [], preferences: {} }));
  await page.route('**/api/version**', readOnly({ version: 'dev' }));
  await page.route('**/api/recommendations/**', readOnly({ run: null }));
  // The reader shell loads saved searches on boot (#581). Left unstubbed it
  // reaches the real backend, 401s on the stub token, and the interceptor
  // clears the session and redirects to /login mid-test — the Edit-feed button
  // detaches under the click. Fast machines beat the round trip; CI loses it.
  await page.route('**/api/saved-searches**', readOnly({ savedSearches: [] }));

  await page.goto('/?subscription=1');
  await page.getByRole('button', { name: 'Edit feed' }).first().click();

  await page.getByRole('button', { name: 'Add or remove tags' }).click();

  const sheet = page.getByRole('dialog', { name: 'Tags' });
  const list = sheet.getByRole('listbox');
  const lastTag = list.getByRole('option').last();
  await expect(lastTag).toHaveText(new RegExp(`Tag number ${TAG_COUNT}`));

  // Drive it the way a thumb does: the gesture starts inside the list and keeps
  // going. Chromium latches a wheel sequence to the scroller it began on, so
  // this must be repeated events rather than one large delta.
  await list.hover();
  for (let tick = 0; tick < 12; tick++) {
    await page.mouse.wheel(0, 200);
  }

  await expect(lastTag).toBeInViewport({ ratio: 1 });
  await lastTag.click();
  await expect(lastTag).toHaveAttribute('aria-selected', 'true');
});
