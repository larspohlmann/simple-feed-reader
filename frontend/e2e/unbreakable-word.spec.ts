// e2e/unbreakable-word.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `reader-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1280, height: 900 };

/**
 * 76 characters with no space, no hyphen and no slash — nothing a browser is
 * allowed to break on by default. Feeds carry these for real: German compound
 * nouns, message ids, base64 tokens, a bare URL the sanitiser stripped the
 * punctuation from.
 */
const LONG_WORD = 'Donaudampfschifffahrtsgesellschaftskapitaenswitwenversicherungspolicennummer';

/**
 * The same problem in body copy — and long enough (128 characters) to overrun
 * the reading measure on a desktop too, not only on a phone. A base64 tracking
 * id pasted into a summary looks like this.
 */
const LONG_TOKEN =
  'aHR0cHM6Ly9maXh0dXJlcy5pbnZhbGlkL2EvdmVyeS9sb25nL3RyYWNraW5nL2lkZW50aWZpZXI' +
  'vd2l0aC9uby9zcGFjZS9hbnl3aGVyZS9pbi9pdC9hdC9hbGwvZXZlcg';

/** Long enough that the magazine planner's text family lays out quote blocks. */
const PROSE =
  'Ein Satz mit ganz gewoehnlichen Woertern, lang genug fuer eine Pull-Quote, ' +
  'damit der Planer auch die textlastigen Vorlagen waehlt und jede Blockform ' +
  'in dieser Liste wirklich vorkommt. Danach folgt das Wort, das die Zeile ' +
  'sprengt: ';

/**
 * The shapes a feed body really arrives in that no reading measure can hold: an
 * unbreakable word, a bare URL, a code block whose lines are longer than the
 * column, and a table with more columns than fit. Each has to stay inside the
 * article — by wrapping, or by scrolling in a box of its own.
 *
 * Three headings, which is the article view's threshold for building a table of
 * contents: the same word then has to fit that narrow list too.
 */
const CONTENT_HTML = `
  <h2>${LONG_WORD}</h2>
  <h2>${LONG_WORD} zwei</h2>
  <h2>${LONG_WORD} drei</h2>
  <p>${PROSE}${LONG_TOKEN}</p>
  <p><a href="https://fixtures.invalid/${LONG_TOKEN}">https://fixtures.invalid/${LONG_TOKEN}</a></p>
  <pre><code>const ${LONG_TOKEN} = ${LONG_TOKEN};</code></pre>
  <table>
    <tr>${Array.from({ length: 12 }, (_, i) => `<th>Spalte ${i + 1}</th>`).join('')}</tr>
    <tr>${Array.from({ length: 12 }, (_, i) => `<td>${LONG_WORD.slice(0, 20)} ${i + 1}</td>`).join('')}</tr>
  </table>
  <blockquote><p>${LONG_WORD}</p></blockquote>
  <ul><li>${LONG_TOKEN}</li></ul>
`;

function entry(id: number, withImage: boolean) {
  return {
    id,
    title: `${LONG_WORD} ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: `${PROSE}${LONG_TOKEN}`,
    contentHtml: CONTENT_HTML,
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

/**
 * Stub the three calls a list and an open article are built from, so this spec
 * owns the text it measures instead of depending on the seeded account's
 * entries (#96). The reader extraction is answered with the same hostile body:
 * reader mode is the default, so that is the markup actually on screen.
 */
async function stubEntries(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries: ENTRIES, nextCursor: null } });
    },
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entry: ENTRIES[0] } });
    },
  );
  await page.route(
    (url) => /^\/api\/entries\/\d+\/reader$/.test(url.pathname),
    async (route) => {
      await route.fulfill({
        status: 200,
        json: {
          status: 'ok',
          url: ENTRIES[0].url,
          title: ENTRIES[0].title,
          byline: null,
          siteName: 'Fixture source',
          contentHtml: CONTENT_HTML,
          excerpt: null,
          readerHero: null,
          originalHero: null,
          extractedAt: '2026-08-01T12:50:34+00:00',
        },
      });
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
  await page.evaluate(() => document.fonts.ready);
  await page.waitForTimeout(300);
}

async function openTheList(page: Page): Promise<void> {
  await expect(page.locator('.rows').first()).toBeVisible();
  await settle(page);
}

async function openTheArticle(page: Page): Promise<void> {
  await page.locator('.rows').first().getByRole('button').first().click();
  await expect(page.locator('app-reader-view .content')).toBeVisible();
  // The contents list is collapsed by default and holds the same headings in a
  // column narrower than the article's, so open it before measuring.
  await page.getByRole('button', { name: 'Contents' }).click();
  await expect(page.locator('.toc-list')).toBeVisible();
  await settle(page);
}

/**
 * Both ways an unbreakable token shows itself, measured inside `root`:
 *
 * - a box wider than the column it lives in — the token painting over the card
 *   border and off the screen;
 * - a box whose content is wider than the box — the token clipped mid-word,
 *   which is what the wider desktop column does with it instead.
 *
 * Content that cannot wrap at all — a code block, a table with more columns
 * than fit — is given a scroller of its own by the article stylesheet, on
 * purpose: it holds the overflow inside the column instead of pushing the page.
 * Those boxes, and everything inside them, are skipped. So is anything set to
 * `white-space: nowrap`, which is how this app says "truncate me" (the magazine
 * kicker line, the sidebar rows).
 */
async function overflowing(page: Page, root: string): Promise<string[]> {
  return page.evaluate((selector) => {
    const container = document.querySelector<HTMLElement>(selector);
    if (!container) return [`no ${selector}`];
    const limit = container.getBoundingClientRect().right;
    const scrolls = (el: HTMLElement) => {
      const overflowX = getComputedStyle(el).overflowX;
      return overflowX === 'auto' || overflowX === 'scroll';
    };
    const describe = (el: HTMLElement, what: string, by: number) =>
      `${el.tagName.toLowerCase()}.${el.className} ${what} by ${Math.round(by)}px`;

    return Array.from(container.querySelectorAll<HTMLElement>('*'))
      .filter((el) => {
        for (let parent = el.parentElement; parent && parent !== container;) {
          if (scrolls(parent)) return false;
          parent = parent.parentElement;
        }
        return !scrolls(el) && getComputedStyle(el).whiteSpace !== 'nowrap';
      })
      .flatMap((el) => {
        const past = el.getBoundingClientRect().right - limit;
        if (past > 1) return [describe(el, 'escaped the column', past)];
        const clipped = el.scrollWidth - el.clientWidth;
        if (clipped > 1) return [describe(el, 'was clipped mid-word', clipped)];
        return [];
      })
      .slice(0, 8);
  }, root);
}

/**
 * Boxes that gained a sideways scroll they should not have — the page, the
 * shell's panes, the list scroller. The article's own code and table scrollers
 * are the deliberate exception; `overflowing` above is what proves those stayed
 * inside their column.
 */
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
      // x-axis scroll-snapping marks a deliberate swipe affordance (the mobile
      // tag row), as does a scroller inside the article body.
      .filter((el) => !getComputedStyle(el).scrollSnapType.startsWith('x'))
      .filter((el) => !el.closest('app-reader-view article'))
      .slice(0, 6)
      .map((el) => `${el.tagName.toLowerCase()}.${el.className}`),
  );
}

for (const [size, viewport] of [
  ['phone', PHONE],
  ['desktop', DESKTOP],
] as const) {
  for (const layout of ['list', 'magazine'] as const) {
    test.describe(`${layout} layout on a ${size}`, () => {
      test.use({ viewport });

      test('an unbreakable word wraps instead of breaking the row', async ({ page }) => {
        await chooseLayout(page, layout);
        const signedIn = await signInAsAdmin(page);
        test.skip(
          !signedIn,
          'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
        );

        await openTheList(page);

        expect(await overflowing(page, '.rows'), 'text overflowed the list').toEqual([]);
        expect(await sidewaysScrollers(page), 'the page gained a sideways scroll').toEqual([]);
      });
    });
  }

  test.describe(`article on a ${size}`, () => {
    test.use({ viewport });

    test('an unbreakable word wraps instead of breaking the article', async ({ page }) => {
      const signedIn = await signInAsAdmin(page);
      test.skip(
        !signedIn,
        'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
      );

      await openTheArticle(page);

      expect(
        await overflowing(page, 'app-reader-view article'),
        'text overflowed the article column',
      ).toEqual([]);
      expect(await sidewaysScrollers(page), 'the page gained a sideways scroll').toEqual([]);
    });
  });
}
