import { expect, test } from '@playwright/test';
import { stubAuthToken } from './support/auth';

// #561: with many tags defined, the last tag pill in the edit-feed dialog could
// not be brought fully into view on a phone.
//
// The tag list's own scroll works. The defect is at its boundary: the dialog
// body still needs a few pixels of its own scroll, and `.tags` carried
// `overscroll-behavior: contain`, which stops a gesture that reaches the end of
// the list from handing the remainder over. On a phone the list fills most of
// the dialog, so nearly every touch starts inside it.

/** Comfortably past the 220px cap, where the dialog's geometry stops changing. */
const TAG_COUNT = 18;

/** Short enough that the dialog body itself must scroll — that is the trap. */
const PHONE = { width: 390, height: 720 };

const TAGS = Array.from({ length: TAG_COUNT }, (_, index) => ({
  id: index + 1,
  name: `Tag number ${index + 1}`,
  color: '#3b82f6',
  icon: 'label',
  position: index,
}));

const SUBSCRIPTIONS = {
  subscriptions: [
    {
      id: 1,
      feedId: 1,
      title: 'Stub Feed',
      faviconUrl: null,
      customTitle: null,
      feedUrl: 'https://example.com/feed',
      siteUrl: 'https://example.com',
      status: 'active',
      sourceFormat: 'xml',
      createdAt: '2026-01-01T00:00:00+00:00',
      lastFetchedAt: '2026-01-01T00:00:00+00:00',
      position: 0,
      tags: [],
      unreadCount: 0,
    },
  ],
  favoritesCount: 0,
  keptCount: 0,
  viewedCount: 0,
};

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

  await page.goto('/?subscription=1');
  await page.getByRole('button', { name: 'Edit feed' }).first().click();

  const tags = page.locator('app-overlay-panel .tags');
  const lastPill = tags.locator('.tag-pill').last();

  // Drive it the way a thumb does: the gesture starts inside the tag list and
  // keeps going. Once the list bottoms out the remainder has to reach the
  // dialog body, or the last row never clears the edge. Chromium latches a
  // wheel sequence to the scroller it began on, so this must be repeated
  // events rather than one large delta.
  await tags.hover();
  for (let tick = 0; tick < 6; tick++) {
    await page.mouse.wheel(0, 200);
  }

  await expect(lastPill).toBeInViewport({ ratio: 1 });
});
