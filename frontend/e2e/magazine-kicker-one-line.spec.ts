// e2e/magazine-kicker-one-line.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/**
 * The title that motivated #155: five clauses, no shortage of spaces to wrap
 * on, and far wider than a 375px phone. The bug was that it pushed the time
 * onto a third line instead of clipping itself.
 */
const LONG_SOURCE =
  'NDR.de - Das Beste am Norden - Radio - Fernsehen - Nachrichten - Sport - Wetter';

/** A phone in portrait, the width the kicker has least room at. */
const PHONE = { width: 375, height: 812 };

function entry(id: number, source: string) {
  return {
    id,
    title: `Fixture entry ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: null,
    excerpt: 'Fixture body.',
    imageUrl: null,
    imageWidth: null,
    imageHeight: null,
    publishedAt: '2026-08-01T12:50:34+00:00',
    createdAt: '2026-08-01T12:50:34+00:00',
    subscriptionId: 1,
    source,
    faviconUrl: null,
    isHidden: false,
    isFavorite: false,
    isKept: false,
  };
}

/**
 * The magazine renders several block shapes (hero, list, source group) and the
 * kicker has to hold its one line in all of them, so this is a handful of
 * entries rather than one.
 */
const ENTRIES = [
  entry(1, LONG_SOURCE),
  entry(2, LONG_SOURCE),
  entry(3, 'Heise'),
  entry(4, LONG_SOURCE),
  entry(5, 'Tagesschau'),
  entry(6, LONG_SOURCE),
];

/**
 * Stub the entry list so these specs own the source name under test. They
 * assert a CSS invariant, not a fetch: reading whatever the seeded account
 * happens to hold made them depend on a fixture that renders on a developer
 * machine and not on a fresh CI database, which is how they rotted (#96).
 *
 * Matched on the pathname so `/api/entries/{id}` and `/api/entries/{id}/state`
 * still reach the real backend.
 */
async function stubEntries(page: Page, entries = ENTRIES): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries, nextCursor: null } });
    },
  );
}

async function signInAsAdmin(page: Page, entries = ENTRIES): Promise<boolean> {
  await stubEntries(page, entries);
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
 * `setViewportSize` resizes the window; it does not wait for the app to lay out
 * again. `LayoutService` learns the new width from a media-query listener, so
 * the sidebar leaves the flow a frame later — and every element under test is
 * already visible from the previous size, so no locator wait gates on that.
 * Measuring inside that window reads the old layout at the new width: on a
 * loaded CI runner the list pane was still sharing the row with the 260px
 * sidebar column, a 115px pane no kicker line can fit (#274).
 *
 * The shell's `.is-narrow` is the settled signal — bound straight from
 * `LayoutService.isNarrow` (`NARROW_QUERY`, `max-width: 720px`).
 */
async function resizeTo(page: Page, viewport: { width: number; height: number }): Promise<void> {
  await page.setViewportSize(viewport);
  const shell = page.locator('app-reader-shell > .body');
  if (viewport.width <= 720) {
    await expect(shell).toHaveClass(/is-narrow/);
    return;
  }
  await expect(shell).not.toHaveClass(/is-narrow/);
}

/**
 * The kicker line must occupy exactly one line in every magazine block, however
 * long the feed's title is — a five-clause title like `LONG_SOURCE` used to
 * push the time onto a third line (#155). Measured, not eyeballed: the rendered
 * row is compared against a single line's height.
 */
test('the kicker line never wraps, at any viewport', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  for (const viewport of [{ width: 1280, height: 900 }, { width: 768, height: 1024 }, PHONE]) {
    await resizeTo(page, viewport);

    const lines = page.locator('app-entry-kicker-line .kicker');
    await expect(lines.first()).toBeVisible();

    const overflowing = await lines.evaluateAll(
      (rows) =>
        rows
          .map((row) => {
            const style = getComputedStyle(row);
            const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.2;
            // A wrapped row is at least two line-boxes tall. The favicon and dot
            // are shorter than the text, so the row's own height is the ceiling.
            return { height: row.getBoundingClientRect().height, lineHeight };
          })
          .filter(({ height, lineHeight }) => height > lineHeight * 1.8).length,
    );

    expect(overflowing, `kicker lines wrapped at ${viewport.width}x${viewport.height}`).toBe(0);
  }
});

/** A 300–399px-wide image fits `split` but not `wide`/`hero`, so these render as
 *  split and thumb cards — the blocks whose text column crosses the threshold. */
const SPLIT_IMAGE =
  'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const SPLIT_ENTRIES = ENTRIES.map((each) => ({
  ...each,
  imageUrl: SPLIT_IMAGE,
  imageWidth: 320,
  imageHeight: 200,
}));

/** 17rem, `$container-kicker-narrow` in `theme/_breakpoints.scss`. */
const KICKER_NARROW_PX = 17 * 16;

test('the time takes its narrow form exactly when the kicker line is narrow', async ({ page }) => {
  const signedIn = await signInAsAdmin(page, SPLIT_ENTRIES);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  // 768 puts a split card below the threshold and a thumb card above it on one page.
  await resizeTo(page, { width: 768, height: 1024 });
  const lines = page.locator('app-entry-kicker-line');
  await expect(lines.first()).toBeVisible();

  const forms = await lines.evaluateAll((rows) =>
    rows.map((row) => ({
      width: row.getBoundingClientRect().width,
      wideShown: getComputedStyle(row.querySelector('.when-wide')!).display !== 'none',
      narrowShown: getComputedStyle(row.querySelector('.when-narrow')!).display !== 'none',
    })),
  );

  const narrow = forms.filter(({ width }) => width < KICKER_NARROW_PX);
  const wide = forms.filter(({ width }) => width >= KICKER_NARROW_PX);
  expect(
    narrow.length,
    'no kicker line below the threshold — the case proves nothing',
  ).toBeGreaterThan(0);
  expect(
    wide.length,
    'no kicker line above the threshold — the case proves nothing',
  ).toBeGreaterThan(0);
  expect(narrow.every(({ wideShown, narrowShown }) => !wideShown && narrowShown)).toBe(true);
  expect(wide.every(({ wideShown, narrowShown }) => wideShown && !narrowShown)).toBe(true);
});

/**
 * These two only ever measure the phone, so they start there instead of
 * resizing into it — the resize is what let #274 measure a half-applied layout.
 */
test.describe('Magazine kicker on a phone', () => {
  test.use({ viewport: PHONE });

  test('a long source never widens the page', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await expect(page.locator('app-entry-kicker-line .kicker').first()).toBeVisible();

    // `nowrap` only shrinks when every ancestor is allowed to; one ancestor stuck
    // at its default `min-width: auto` pushes the whole document sideways.
    // The document is not the only box that can gain a sideways scroll: the list
    // has its own scroller, and that is what shifted under the fixed header when
    // this regressed. Check every element that can scroll horizontally.
    const offenders = await page.evaluate(() => {
      const scrollers = Array.from(document.querySelectorAll<HTMLElement>('body *'))
        .concat(document.documentElement)
        .filter((el) => el.scrollWidth > el.clientWidth + 1)
        // A clipped element (an ellipsised source) always overflows its own box
        // by design. Only a box that actually scrolls can shift what the reader
        // sees, so ignore anything that merely clips.
        .filter((el) => {
          if (el === document.documentElement || el === document.body) return true;
          const overflowX = getComputedStyle(el).overflowX;
          return overflowX === 'auto' || overflowX === 'scroll';
        })
        // x-axis scroll-snapping marks a deliberate swipe affordance (the mobile
        // tag row). #155 was the opposite: an ancestor refusing to shrink. Keying
        // on the CSS signal rather than a selector keeps document/body — and so a
        // snap-scroller that does push the page sideways — still caught.
        .filter((el) => !getComputedStyle(el).scrollSnapType.startsWith('x'))
        .map((el) => ({
          who: `${el.tagName.toLowerCase()}.${el.className}`,
          scrollWidth: el.scrollWidth,
          clientWidth: el.clientWidth,
        }));

      const viewport = document.documentElement.clientWidth;
      const wider = Array.from(document.querySelectorAll<HTMLElement>('.rows.magazine *'))
        .filter((el) => el.getBoundingClientRect().width > viewport)
        .slice(0, 8)
        .map(
          (el) =>
            `${el.tagName.toLowerCase()}.${el.className}=${Math.round(el.getBoundingClientRect().width)}`,
        );
      return { scrollers: scrollers.slice(0, 6), wider: wider.slice(0, 6) };
    });

    expect(
      offenders.scrollers,
      `sideways scroll: ${JSON.stringify(offenders.scrollers)} | wider than parent: ${offenders.wider.join(' | ')}`,
    ).toEqual([]);
  });

  test('a source too long for the row is ellipsised, never the time', async ({ page }) => {
    const signedIn = await signInAsAdmin(page);
    test.skip(
      !signedIn,
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    const line = page.locator('app-entry-kicker-line').first();
    await expect(line).toBeVisible();

    // The time is the whole point of the row and must always render in full.
    const when = line.locator('.when');
    await expect(when).not.toBeEmpty();
    const clipped = await when.evaluate((el) => el.scrollWidth > el.clientWidth + 1);
    expect(clipped, 'the relative time was clipped instead of the source').toBe(false);

    // The source is the elastic one: it may clip, and must do so with an ellipsis.
    const source = line.locator('.source');
    await expect(source).toHaveCSS('text-overflow', 'ellipsis');
    await expect(source).toHaveCSS('white-space', 'nowrap');
  });
});
