// e2e/header-scroll-mobile.spec.ts
import { test, expect, Page } from '@playwright/test';
import { readerFailedJson, savedSearchWire, savedSearchesJson } from './support/reader';

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
  isHidden: false,
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
      json: {
        state: { entryId: 1, isHidden: true, isFavorite: false, isKept: false, hiddenAt: 'x' },
      },
    });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
  });
}

/** The list's own scroll container — the scroller that drives the app bar. */
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

/**
 * Wait for the header's 0.2s transform transition to end. A fixed sleep is
 * animation time, not wall-clock: under parallel workers the compositor falls
 * behind, the sample lands mid-transition, and it reads exactly like the #87
 * regression this file guards. The timeout is a backstop for
 * `prefers-reduced-motion`, where `transitionend` never fires.
 */
async function waitForHeaderTransitionEnd(page: Page): Promise<void> {
  await page.locator('app-reader-header').evaluate(
    (el) =>
      new Promise<void>((resolve) => {
        const finish = () => {
          el.removeEventListener('transitionend', finish);
          resolve();
        };
        el.addEventListener('transitionend', finish, { once: true });
        setTimeout(finish, 2000);
      }),
  );
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
    await waitForHeaderTransitionEnd(page);
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
    await waitForHeaderTransitionEnd(page);

    const anchor = page.getByText('Entry number 12', { exact: false }).first();
    const before = (await anchor.boundingBox())!;
    // Scrolling up expands the header again.
    await rows.evaluate((el) => el.scrollBy(0, -100));
    await waitForHeaderTransitionEnd(page);
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
    await waitForHeaderTransitionEnd(page);
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

  // #128: returning from a full-screen article used to spring the retracted
  // header back and shift the list. The historical trigger was oblique —
  // closing the article remounted the header's tag row, the browser re-snapped
  // its x-scroll, and that scroll event's `scrollTop: 0` satisfied the header
  // logic's near-top rule; the rows also shifted because the bar's published
  // height followed its shorter article form. The article is its own layer now
  // (own toolbar, above the untouched bar), but this walks the full path with
  // a real tag row in place to keep it that way.
  test('returning from an article keeps the retracted header retracted and the list still', async ({
    page,
  }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await stubEntries(page);
    // Enough tags, long enough, that the mobile tag row overflows and the
    // browser has a snap position to re-settle to when the row remounts.
    await page.route('**/api/tags', async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({
        status: 200,
        json: {
          tags: Array.from({ length: 8 }, (_, i) => ({
            id: i + 1,
            name: `Long tag name number ${i + 1}`,
            color: null,
            icon: null,
            position: i,
          })),
        },
      });
    });
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await expect(page.locator('app-reader-header .tagrow')).toBeVisible();
    await settle(page);

    // Retract the header, then note where a row rests.
    await rows.evaluate((el) => el.scrollBy(0, 800));
    await page.waitForTimeout(400);
    const header = page.locator('app-reader-header');
    await expect(header).toHaveClass(/hidden/);
    const anchor = page.getByText('Entry number 12', { exact: false }).first();
    const before = (await anchor.boundingBox())!;
    const scrollBefore = await rows.evaluate((el) => el.scrollTop);

    // Open whichever entry is on screen — clicking through Playwright would
    // auto-scroll the target into view and move the very offset under test.
    await page.evaluate(() => {
      const visible = [...document.querySelectorAll('.rows app-entry-row .title')].find((t) => {
        const b = t.getBoundingClientRect();
        return b.top > 100 && b.top < 500;
      }) as HTMLElement;
      visible.click();
    });
    await expect(page.locator('app-reader-view')).toBeVisible();
    // The article brings its own toolbar; the list's bar stays retracted
    // beneath the overlay, untouched.
    await expect(page.locator('app-reader-view .bar .close')).toBeVisible();
    await expect(header).toHaveClass(/hidden/);
    await page.waitForTimeout(400);

    // Back to the list (same leave path as the swipe), across the 220ms
    // slide-out and the route change.
    await page.locator('app-reader-view .bar .close').click();
    await expect(page.locator('app-reader-view')).toBeHidden();
    await page.waitForTimeout(500);

    // The list is exactly as left: still scrolled, header still retracted,
    // rows at the same pixel.
    await expect(header).toHaveClass(/hidden/);
    expect(await rows.evaluate((el) => el.scrollTop)).toBe(scrollBefore);
    const after = (await anchor.boundingBox())!;
    expect(after.y).toBeCloseTo(before.y, 0);
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
      await route.fulfill({ status: 200, json: readerFailedJson('unextractable') });
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

  // #630: pick a list from the drawer while the current list is scrolled down.
  // The outgoing list stays rendered at its offset until the new page lands
  // (#254); the app bar used to resolve its state from that stale offset when
  // the drawer auto-closed and stay retracted over the new list, which opens at
  // the top — leaving an empty band where the bar belongs. In magazine layout
  // (the default) no incidental scroll event re-showed it. The bar now mirrors
  // the list's own collapse state, which resets to the top on the new list.
  test('the app bar returns for a list chosen from the drawer while scrolled down', async ({
    page,
  }) => {
    // Magazine layout on purpose (no list pin). A unique feed per entry keeps
    // magazine from collapsing the run into one group, so the list scrolls.
    await page.goto('/login');
    await page.locator('input[type=email]').fill(ADMIN_EMAIL);
    await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Sign in' }).click();
    const sidebar = page.getByRole('navigation', { name: 'Feeds' });
    const loginError = page.getByRole('alert');
    await expect(sidebar.or(loginError)).toBeVisible();
    test.skip(!(await sidebar.isVisible()), 'seeded admin login unavailable');

    const perFeed = ENTRIES.map((e, i) => ({ ...e, subscriptionId: i + 1 }));
    await page.route('**/api/entries*', async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: perFeed, nextCursor: null } });
    });
    await page.route('**/api/saved-searches', async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: savedSearchesJson(savedSearchWire()) });
    });
    await page.reload();

    const rows = page.locator(ROWS);
    await expect(rows).toBeVisible();
    await settle(page);

    // Scroll down: the bar retracts.
    await rows.evaluate((el) => el.scrollBy(0, 400));
    await waitForHeaderTransitionEnd(page);
    const header = page.locator('app-reader-header');
    await expect(header).toHaveClass(/hidden/);

    // Open the drawer with an edge-swipe (the bar's menu button is off-screen
    // while retracted, exactly as the user finds it), then pick the saved search.
    await rows.evaluate((el) => {
      const touch = (x: number) => [
        new Touch({ identifier: 1, target: el, clientX: x, clientY: 300 }),
      ];
      el.dispatchEvent(new TouchEvent('touchstart', { bubbles: true, touches: touch(6) }));
      el.dispatchEvent(new TouchEvent('touchmove', { bubbles: true, touches: touch(220) }));
      el.dispatchEvent(new TouchEvent('touchend', { bubbles: true, touches: [] }));
    });
    await expect(header).not.toHaveClass(/hidden/); // force-shown while the drawer is open
    const savedItem = page.locator('.savedsearch-item').first();
    if (!(await savedItem.isVisible())) {
      await page.locator('.savedsearch-toggle').first().click(); // expand the section
    }
    await savedItem.click();

    // The new list is at the top, so the bar must show — not inherit the old
    // scroll. A retracted bar slides off the top (translateY -100%); a shown one
    // rests at the top of the viewport, so its box must be on screen.
    await waitForHeaderTransitionEnd(page);
    await expect(header).not.toHaveClass(/hidden/);
    const headerBox = (await header.boundingBox())!;
    expect(headerBox.y).toBeGreaterThanOrEqual(-1);
  });
});
