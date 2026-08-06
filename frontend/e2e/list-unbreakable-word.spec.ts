// e2e/list-unbreakable-word.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `reader-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/**
 * 76 characters with no space, no hyphen and no slash — nothing a browser is
 * allowed to break on by default. Feeds carry these for real: German compound
 * nouns, message ids, base64 tokens, a bare URL the sanitiser stripped the
 * punctuation from.
 */
const LONG_TITLE_WORD =
  'Donaudampfschifffahrtsgesellschaftskapitaenswitwenversicherungspolicennummer';

/**
 * The same problem in the body copy, which every block renders as a dek — and
 * long enough (128 characters) to overrun the reading measure on a desktop too,
 * not only a phone. A base64 tracking id pasted into a summary looks like this.
 */
const LONG_SUMMARY_WORD =
  'aHR0cHM6Ly9maXh0dXJlcy5pbnZhbGlkL2EvdmVyeS9sb25nL3RyYWNraW5nL2lkZW50aWZpZXI' +
  'vd2l0aC9uby9zcGFjZS9hbnl3aGVyZS9pbi9pdC9hdC9hbGwvZXZlcg';

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1280, height: 900 };

/** Long enough that the planner's text family also lays out quote blocks. */
const PROSE =
  'Ein Satz mit ganz gewoehnlichen Woertern, lang genug fuer eine Pull-Quote, ' +
  'damit der Planer auch die textlastigen Vorlagen waehlt und jede Blockform ' +
  'in dieser Liste wirklich vorkommt. Danach folgt das Wort, das die Zeile ' +
  'sprengt: ';

function entry(id: number, withImage: boolean) {
  return {
    id,
    title: `${LONG_TITLE_WORD} ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: `${PROSE}${LONG_SUMMARY_WORD}`,
    contentHtml: `<p>${PROSE}${LONG_SUMMARY_WORD}</p>`,
    imageUrl: withImage ? `https://fixtures.invalid/${id}.jpg` : null,
    imageWidth: withImage ? 1200 : null,
    imageHeight: withImage ? 800 : null,
    publishedAt: '2026-08-01T12:50:34+00:00',
    createdAt: '2026-08-01T12:50:34+00:00',
    subscriptionId: (id % 3) + 1,
    source: 'Fixture source',
    faviconUrl: null,
    isRead: false,
    isFavorite: false,
    isKept: false,
  };
}

/** A mix of image-bearing and text-only entries, so the magazine planner emits
 *  both families of block rather than one shape repeated. */
const ENTRIES = Array.from({ length: 12 }, (_, index) => entry(index + 1, index % 3 === 0));

async function stubEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
}

/**
 * Choose the reading layout before the app boots. The header's layout buttons
 * are unreachable on a phone — the reader hides them behind the drawer — so
 * clicking them would restrict this spec to desktop widths, which is the one
 * width where a long word has room to fit.
 */
async function chooseLayout(page: Page, layout: 'list' | 'magazine'): Promise<void> {
  await page.addInitScript((mode) => localStorage.setItem('sfr.layout', mode), layout);
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  await stubEntries(page);
  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in' }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/**
 * The Material Symbols webfont lands a beat after first paint and reflows every
 * row, so measuring before it settles reads stale geometry.
 */
async function settle(page: Page): Promise<void> {
  await expect(page.locator('.rows').first()).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
}

/** Every element inside the list that is wider than the list's own content box:
 *  the signature of a token nothing was allowed to break. */
async function escapees(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const rows = document.querySelector<HTMLElement>('.rows');
    if (!rows) return ['no .rows'];
    const limit = rows.getBoundingClientRect().right;
    return Array.from(rows.querySelectorAll<HTMLElement>('*'))
      .filter((el) => el.getBoundingClientRect().right > limit + 1)
      .slice(0, 8)
      .map(
        (el) =>
          `${el.tagName.toLowerCase()}.${el.className}=${Math.round(
            el.getBoundingClientRect().right - limit,
          )}px past`,
      );
  });
}

/** Boxes that gained a sideways scroll, ignoring deliberate swipe affordances. */
async function sidewaysScrollers(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    Array.from(document.querySelectorAll<HTMLElement>('body *'))
      .concat(document.documentElement)
      .filter((el) => el.scrollWidth > el.clientWidth + 1)
      .filter((el) => {
        if (el === document.documentElement || el === document.body) return true;
        const overflowX = getComputedStyle(el).overflowX;
        return overflowX === 'auto' || overflowX === 'scroll';
      })
      .filter((el) => !getComputedStyle(el).scrollSnapType.startsWith('x'))
      .slice(0, 6)
      .map((el) => `${el.tagName.toLowerCase()}.${el.className}`),
  );
}

for (const layout of ['list', 'magazine'] as const) {
  for (const [size, viewport] of [
    ['phone', PHONE],
    ['desktop', DESKTOP],
  ] as const) {
    test.describe(`${layout} layout on a ${size}`, () => {
      test.use({ viewport });

      test('an unbreakable word wraps instead of breaking the layout', async ({ page }) => {
        await chooseLayout(page, layout);
        const signedIn = await signInAsAdmin(page);
        test.skip(
          !signedIn,
          'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
        );

        await settle(page);

        expect(await escapees(page), 'text escaped the list column').toEqual([]);
        expect(await sidewaysScrollers(page), 'the page gained a sideways scroll').toEqual([]);
      });
    });
  }
}
