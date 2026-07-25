// e2e/header-scroll-mobile.spec.ts
import { test, expect, Page } from '@playwright/test';

// Same seeded admin as reader-smoke.spec.ts (`bin/console app:e2e:seed-admin`).
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 667 };

/**
 * Enough entries to scroll well past the point where the header retracts
 * (`HEADER_NEAR_TOP` is 40px). Stubbed rather than seeded: this measures
 * layout, and a fixed list keeps the row geometry deterministic.
 */
const ENTRIES = Array.from({ length: 30 }, (_, i) => ({
  id: i + 1,
  title: `Entry number ${i + 1}`,
  url: `https://example.invalid/${i + 1}`,
  author: null,
  summary: 'A summary long enough to give the row some height. '.repeat(3),
  contentHtml: '<p>body</p>',
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
  isRead: false,
  isFavorite: false,
  isKept: false,
}));

async function signInAsAdmin(page: Page): Promise<boolean> {
  // The default layout is magazine, which collapses a run of same-source
  // entries into a group and renders only three of them. Pin the flat list so
  // every stubbed entry is a row and the geometry is predictable.
  await page.addInitScript(() => localStorage.setItem('sfr.layout', 'list'));
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

async function stubEntries(page: Page): Promise<void> {
  await page.route('**/api/entries/*/state', async (route) => {
    await route.fulfill({
      status: 200,
      json: { state: { entryId: 1, isRead: true, isFavorite: false, isKept: false, readAt: 'x' } },
    });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
  });
}

/** The list's own scroll container — the element the shell listens to. */
const ROWS = '.rows';

/**
 * Wait for the layout to stop moving on its own before measuring. The Material
 * Symbols webfont lands a beat after first paint and reflows every row by about
 * a pixel; measured too early, that shows up as ~10px of drift across 30 rows
 * and reads exactly like the bug under test.
 */
async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
}

test.describe('Hide-on-scroll header on a phone', () => {
  test.use({ viewport: PHONE });

  test('retracting the header does not move the content', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    const anchor = page.getByText('Entry number 12', { exact: false }).first();
    await expect(anchor).toBeVisible();
    await settle(page);

    const header = page.locator('app-reader-header');
    const headerBefore = (await header.boundingBox())!;

    // Scroll far enough to retract the header, and let the 0.2s transition end.
    const before = (await anchor.boundingBox())!;
    await rows.evaluate((el) => el.scrollBy(0, 300));
    await page.waitForTimeout(400);
    const after = (await anchor.boundingBox())!;

    // The header really did retract — otherwise this test proves nothing.
    const headerAfter = (await header.boundingBox())!;
    expect(headerAfter.y).toBeLessThan(headerBefore.y - 20);

    // The row moved by the scroll distance and by nothing else. Before the fix
    // the header's collapse handed the content area ~96px of extra height and
    // the row travelled that much further.
    expect(after.y).toBeCloseTo(before.y - 300, 0);
  });

  test('expanding the header again does not move the content', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await settle(page);
    await rows.evaluate((el) => el.scrollBy(0, 400));
    await page.waitForTimeout(400);

    const anchor = page.getByText('Entry number 12', { exact: false }).first();
    const before = (await anchor.boundingBox())!;
    // Scrolling up expands the header again.
    await rows.evaluate((el) => el.scrollBy(0, -100));
    await page.waitForTimeout(400);
    const after = (await anchor.boundingBox())!;

    expect(after.y).toBeCloseTo(before.y + 100, 0);
  });

  // The article renders as an overlay over the still-mounted list, and its
  // scrolling host is transparent on purpose so a swipe-away reveals that list.
  // Anything the article reserves at its top therefore has to be reserved on
  // the opaque panel inside it, or the list shows through the gap.
  test('an open article is opaque all the way to the top', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();
    await expect(page.locator(ROWS)).toBeVisible();
    await settle(page);

    await page.getByText('Entry number 1', { exact: false }).first().click();
    const host = page.locator('app-reader-view');
    const panel = host.locator('.reader');
    await expect(panel).toBeVisible();

    const hostBox = (await host.boundingBox())!;
    const panelBox = (await panel.boundingBox())!;
    expect(panelBox.y).toBeCloseTo(hostBox.y, 0);
    expect(panelBox.height).toBeGreaterThanOrEqual(hostBox.height - 1);
  });

  test('the content area keeps its height while the header slides', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await settle(page);
    const height = () => rows.evaluate((el) => el.clientHeight);

    const before = await height();
    await rows.evaluate((el) => el.scrollBy(0, 300));
    await page.waitForTimeout(400);
    expect(await height()).toBe(before);
  });

  test('the bar’s empty middle and the corner button both return the list to the top', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await settle(page);

    // Well past the 500px threshold, so the corner button is showing.
    await rows.evaluate((el) => el.scrollTo({ top: 1500 }));
    expect(await rows.evaluate((el) => el.scrollTop)).toBeGreaterThan(1000);

    const corner = page.locator('app-entry-list app-to-top-button');
    await expect(corner).toBeVisible();
    await corner.click();
    await expect.poll(() => rows.evaluate((el) => el.scrollTop)).toBe(0);
    await expect(corner).toBeHidden();

    // And again via the empty middle of the app bar. Scrolling down retracts the
    // bar, so scroll back up a little first to bring it into reach — exactly what
    // the user does.
    await rows.evaluate((el) => el.scrollTo({ top: 1500 }));
    await page.waitForTimeout(400);
    await rows.evaluate((el) => el.scrollBy(0, -100));
    await page.waitForTimeout(400);

    await page.locator('app-reader-header .tap-to-top').click();
    await expect.poll(() => rows.evaluate((el) => el.scrollTop)).toBe(0);
  });

  // The article view has its own copy of the shared back-to-top component, but
  // pinned to the viewport (`position: fixed`) rather than the list's pane
  // (`position: absolute`) — see to-top-button.component.ts. Pin that contract
  // here so an extraction that hoists the two into one place can't silently
  // regress it.
  test('the article’s back-to-top button stays pinned while the article scrolls', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    // Force extraction to fail so the view is deterministically in 'original'
    // mode (the entry's own contentHtml/summary — see displayHtml()), rather
    // than depending on the real backend's attempt to fetch the seeded entry's
    // actual URL failing on its own. Left unstubbed, a day this extraction
    // starts succeeding flips the view to 'reader' mode, "Paragraph 0" never
    // renders, and the test fails for a reason that has nothing to do with the
    // back-to-top button — plus every run would make a real outbound HTTP call.
    await page.route('**/api/entries/*/reader', async (route) => {
      await route.fulfill({
        status: 200,
        json: { status: 'failed', url: null, reason: 'unextractable' },
      });
    });
    // A stub with real height drives the article's own scroller past the
    // back-to-top threshold. Overriding after stubEntries() means this route
    // wins (Playwright tries the most-recently-registered matching handler
    // first).
    await page.route('**/api/entries*', async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const tallEntries = ENTRIES.map((e) =>
        e.id === 1
          ? {
              ...e,
              contentHtml: Array.from(
                { length: 20 },
                (_, i) =>
                  `<p>Paragraph ${i} of filler text, long enough to give the article real height so it can scroll well past the back-to-top threshold.</p>`,
              ).join(''),
            }
          : e,
      );
      await route.fulfill({ status: 200, json: { entries: tallEntries, nextCursor: null } });
    });
    await page.reload();
    await expect(page.locator(ROWS)).toBeVisible();
    await settle(page);

    await page.getByText('Entry number 1', { exact: false }).first().click();
    const article = page.locator('app-reader-view');
    await expect(article).toBeVisible();
    await expect(page.getByText('Paragraph 0 of filler text').first()).toBeVisible();

    await article.evaluate((el) => el.scrollTo({ top: 900 }));
    const button = page.locator('app-reader-view app-to-top-button');
    await expect(button).toBeVisible();

    // Sample across frames rather than measuring once, because the regression
    // this guards against is transient: a transform on the overlay makes it the
    // containing block for this fixed-position button, and while that holds the
    // button resolves against the article's own scrolled box and rides off the
    // top of the screen (#100). Every sampled y must stay on screen; x is free
    // to move, since the button rides along with the slide-in.
    //
    // Honest limitation: the overlay's animation is ~220ms and several
    // Playwright round-trips have already happened by the time this runs, so
    // sampling often starts after it has finished. This catches the regression
    // when it wins the race and never false-fails when it doesn't — the actual
    // guarantee is the wrapper element in reader-shell.component.html, not this
    // assertion. Do not add a wait here to "stabilise" it; that would restore
    // the blind spot this replaced.
    const ys = await button.evaluate(
      (el) =>
        new Promise<number[]>((resolve) => {
          const samples: number[] = [];
          const collect = () => {
            samples.push(el.getBoundingClientRect().y);
            if (samples.length < 20) requestAnimationFrame(collect);
            else resolve(samples);
          };
          requestAnimationFrame(collect);
        }),
    );
    for (const y of ys) expect(y).toBeGreaterThan(0);

    // By now the animation (~220ms, comfortably inside the ~330ms sampled
    // above) has settled, so this is the steady-state position.
    const first = (await button.boundingBox())!;

    await article.evaluate((el) => el.scrollTo({ top: 1400 }));
    const second = (await button.boundingBox())!;

    // Fixed to the viewport, so 500px of article scroll must not move it.
    expect(second.y).toBeCloseTo(first.y, 0);
    expect(second.x).toBeCloseTo(first.x, 0);
  });
});
