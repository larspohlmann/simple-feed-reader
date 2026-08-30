// e2e/magazine-airy-style.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-kicker-one-line.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

/** An inline fixture image: an external host 404'd during Task 7 and silently
 *  cost that task its radius check. */
const IMG =
  'data:image/svg+xml;base64,' +
  Buffer.from(
    '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800"><rect width="1200" height="800" fill="#8a8a86"/></svg>',
  ).toString('base64');

function entry(id: number, source: string) {
  return {
    id,
    title: `Fixture entry ${id}`,
    url: `https://fixtures.invalid/${id}`,
    author: null,
    summary: 'A summary long enough that the planner has a dek to place.',
    contentHtml: '<p>Fixture body.</p>',
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

/** Enough entries, across enough sources, that the planner emits several slots. */
const ENTRIES = [
  entry(1, 'Heise'),
  entry(2, 'Tagesschau'),
  entry(3, 'Heise'),
  entry(4, 'Der Spiegel'),
  entry(5, 'Tagesschau'),
  entry(6, 'Der Spiegel'),
];

/** A large, non-portrait image on every entry so `hero` (>= 500px) and `wide`
 *  (>= 400px) both fit without demoting; seven entries across three sources
 *  spill a `wide` opener onto a second page whose template opens `hero`. */
function imageEntry(id: number, source: string) {
  return { ...entry(id, source), imageUrl: IMG, imageWidth: 1200, imageHeight: 800 };
}

const IMAGE_ENTRIES = [
  imageEntry(1, 'Heise'),
  imageEntry(2, 'Tagesschau'),
  imageEntry(3, 'Heise'),
  imageEntry(4, 'Der Spiegel'),
  imageEntry(5, 'Tagesschau'),
  imageEntry(6, 'Der Spiegel'),
  imageEntry(7, 'Heise'),
];

/**
 * `/api/me` is rewritten, not replaced: the profile carries roles, mail state and
 * AI readiness the shell reads on boot, and a malformed one 401s into a login
 * redirect.
 */
async function stubAccount(
  page: Page,
  magazineStyle: 'boxed' | 'airy',
  entries: unknown[],
): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      const response = await route.fetch();
      const profile = await response.json();
      await route.fulfill({
        response,
        json: { ...profile, preferences: { ...profile.preferences, magazineStyle } },
      });
    },
  );

  await page.route(
    (url) => url.pathname === '/api/entries',
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      await route.fulfill({ status: 200, json: { entries, nextCursor: null } });
    },
  );
}

/** The store applies its own optimistic patch before this resolves, so the
 *  response body is never read — only a non-error status keeps the row from
 *  reverting (and so from un-leaving) once the PATCH round-trips. */
async function stubEntryStateWrites(page: Page): Promise<void> {
  await page.route(
    (url) => /^\/api\/entries\/\d+\/state$/.test(url.pathname),
    async (route) => {
      if (route.request().method() !== 'PATCH') return route.fallback();
      await route.fulfill({ status: 200, json: { state: {} } });
    },
  );
}

async function signInAsAdmin(page: Page): Promise<boolean> {
  // Pins the resolved theme so the background-colour assertions below are not
  // at the mercy of the runner's OS colour scheme.
  await page.addInitScript(() => localStorage.setItem('sfr.theme', 'light'));

  await page.goto('/login');
  await page.locator('input[type=email]').fill(ADMIN_EMAIL);
  await page.locator('input[type=password]').fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();

  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  const loginError = page.getByRole('alert');
  await expect(sidebar.or(loginError)).toBeVisible();
  return sidebar.isVisible();
}

/** Computed styles: a class proves the binding fired, not that it landed. */
async function blockBorderWidth(page: Page): Promise<string> {
  const rows = page.locator('.rows.magazine');
  await expect(rows).toBeVisible();

  const block = page
    .locator('.rows.magazine app-entry-thumb .thumb, .rows.magazine app-entry-kicker .kicker-card')
    .first();
  await expect(block).toBeVisible();

  return block.evaluate((el) => getComputedStyle(el).borderTopWidth);
}

/** The slot's inner wrapper carries the rule AND its padding — the pairing the
 *  brief calls out: the rule sits on `.row-slot-inner`, not `.magazine-slot`,
 *  so one keyframe (`magazine-rule-pad-close`) can zero both on removal. */
async function slotInnerStyle(page: Page): Promise<{ borderTopWidth: string; paddingTop: string }> {
  const inner = page.locator('.rows.magazine .magazine-slot .row-slot-inner').nth(1);
  await expect(inner).toBeVisible();
  return inner.evaluate((el) => {
    const style = getComputedStyle(el);
    return { borderTopWidth: style.borderTopWidth, paddingTop: style.paddingTop };
  });
}

async function backgrounds(page: Page): Promise<{ rows: string; header: string }> {
  const rows = page.locator('.rows.magazine');
  const header = page.locator('.list-header');
  await expect(rows).toBeVisible();
  await expect(header).toBeVisible();
  return {
    rows: await rows.evaluate((el) => getComputedStyle(el).backgroundColor),
    header: await header.evaluate((el) => getComputedStyle(el).backgroundColor),
  };
}

/** The gap between a full-bleed image's bottom edge and its kicker's top edge —
 *  airy's `--card-pad: 0` leaves the image as the only hard edge against the
 *  body text, closed back to `--space-4` on the body's own `padding-top`. */
async function imageToKickerGap(page: Page, selector: string): Promise<number> {
  const block = page.locator(selector).first();
  await expect(block).toBeVisible();
  const image = block.locator('.img');
  const kicker = block.locator('.kicker');
  const [imageBox, kickerBox] = await Promise.all([image.boundingBox(), kicker.boundingBox()]);
  if (!imageBox || !kickerBox) throw new Error(`${selector}: image or kicker did not lay out`);
  return kickerBox.y - (imageBox.y + imageBox.height);
}

test('the airy magazine drops the card border and rules the slots instead', async ({ page }) => {
  await stubAccount(page, 'airy', ENTRIES);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await expect(page.locator('.rows.magazine')).toHaveClass(/airy/);

  expect(await blockBorderWidth(page), 'an airy block still draws a card border').toBe('0px');

  const inner = await slotInnerStyle(page);
  expect(inner.borderTopWidth, 'the hairline rule is missing from .row-slot-inner').toBe('1px');
  expect(inner.paddingTop, 'the rule sits without the row-gap padding above it').toBe('24px');

  const colors = await backgrounds(page);
  expect(colors.rows, 'the airy canvas should take the card colour').toBe('rgb(255, 255, 255)');
  expect(colors.header, 'the sticky header should match the airy canvas').toBe(
    'rgb(255, 255, 255)',
  );
});

test('the boxed magazine keeps the card border and rules nothing', async ({ page }) => {
  await stubAccount(page, 'boxed', ENTRIES);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await expect(page.locator('.rows.magazine')).not.toHaveClass(/airy/);

  expect(await blockBorderWidth(page), 'the boxed card lost its border').toBe('1px');

  const inner = await slotInnerStyle(page);
  expect(inner.borderTopWidth, 'a boxed slot must never draw the hairline').toBe('0px');
  expect(inner.paddingTop, 'a boxed slot must never reserve the rule padding').toBe('0px');

  const colors = await backgrounds(page);
  expect(colors.rows, 'a boxed canvas has no card colour of its own').toBe('rgba(0, 0, 0, 0)');
  expect(colors.header, 'the sticky header should keep the boxed page colour').toBe(
    'rgb(245, 245, 244)',
  );
});

test('airy hero and wide images run full bleed with rounded corners', async ({ page }) => {
  await stubAccount(page, 'airy', IMAGE_ENTRIES);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  const heroImg = page.locator('.rows.magazine app-entry-hero .img').first();
  const wideImg = page.locator('.rows.magazine app-entry-wide .img').first();
  await expect(heroImg).toBeVisible();
  await expect(wideImg).toBeVisible();

  expect(
    await heroImg.evaluate((el) => getComputedStyle(el).borderRadius),
    'the hero image should carry --radius-lg on every corner',
  ).toBe('12px');
  expect(
    await wideImg.evaluate((el) => getComputedStyle(el).borderRadius),
    'the wide image should carry --radius-lg on every corner',
  ).toBe('12px');

  expect(
    await imageToKickerGap(page, '.rows.magazine app-entry-hero'),
    'the hero image should leave --space-4 above its kicker',
  ).toBeCloseTo(16, 0);
  expect(
    await imageToKickerGap(page, '.rows.magazine app-entry-wide'),
    'the wide image should leave --space-4 above its kicker',
  ).toBeCloseTo(16, 0);
});

test('boxed hero and wide images keep square corners and the card padding', async ({ page }) => {
  await stubAccount(page, 'boxed', IMAGE_ENTRIES);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  const heroImg = page.locator('.rows.magazine app-entry-hero .img').first();
  const wideImg = page.locator('.rows.magazine app-entry-wide .img').first();
  await expect(heroImg).toBeVisible();
  await expect(wideImg).toBeVisible();

  // The card's own `overflow: hidden` clips the top corners; an own-radius on
  // the image would curve the bottom two loose inside the card as well.
  expect(
    await heroImg.evaluate((el) => getComputedStyle(el).borderRadius),
    'the hero image must not round its own corners',
  ).toBe('0px');
  expect(
    await wideImg.evaluate((el) => getComputedStyle(el).borderRadius),
    'the wide image must not round its own corners',
  ).toBe('0px');

  const heroBody = page.locator('.rows.magazine app-entry-hero .body').first();
  const wideBody = page.locator('.rows.magazine app-entry-wide .body').first();
  expect(
    await heroBody.evaluate((el) => getComputedStyle(el).padding),
    'the boxed hero body keeps its card padding',
  ).toBe('12px 16px');
  expect(
    await wideBody.evaluate((el) => getComputedStyle(el).padding),
    'the boxed wide body keeps its card padding',
  ).toBe('12px 16px');
});

/**
 * The regression this branch shipped twice: the first fix zeroed
 * `padding-top` on removal but left `border-top` on `.magazine-slot` itself,
 * which `grid-template-rows: 0fr` cannot collapse — a permanent 1px residue
 * under `forwards`. The fix moved the border onto `.row-slot-inner` so one
 * keyframe (`magazine-rule-pad-close`) closes both. Nothing before this test
 * drove a real removal, so a third property added to that element and left
 * open would pass every other assertion in this file unchanged.
 */
test('an un-favourited row collapses its slot to zero height, not a residue', async ({ page }) => {
  const favourited = ENTRIES.map((e) => ({ ...e, isFavorite: true }));
  await stubAccount(page, 'airy', favourited);
  await stubEntryStateWrites(page);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.getByRole('link', { name: 'Favorites' }).click();

  // Not the first slot: that one carries no rule to close, so a border or
  // padding left behind would not show up on it.
  const slot = page.locator('.rows.magazine .magazine-slot').nth(1);
  await expect(slot).toBeVisible();

  // Ambiguous without `exact`: the enclosing card is itself a `role="button"`
  // whose name-from-content rolls up every descendant's own accessible name,
  // "Favorite" included.
  await slot.getByRole('button', { name: 'Favorite', exact: true }).click();
  await expect(slot, 'un-favouriting should drop the row out of the Favorites view').toHaveClass(
    /leaving/,
  );

  // `row-leave` + `magazine-rule-pad-close` both run on a 0.26s `forwards`
  // animation; poll rather than sleep so the assertion reads the settled state.
  await expect
    .poll(() => slot.evaluate((el) => Math.round(el.getBoundingClientRect().height)), {
      timeout: 2000,
    })
    .toBe(0);
});

/**
 * Issue #723: "No rule above the first block." The rule selector must not
 * match a slot whose only preceding siblings are collapsed/leaving — else
 * un-favouriting the FIRST entry leaves the new first block still drawing
 * the divider that used to sit between it and its predecessor.
 */
test('un-favouriting the first entry leaves no rule above the new first block', async ({
  page,
}) => {
  const favourited = ENTRIES.map((e) => ({ ...e, isFavorite: true }));
  await stubAccount(page, 'airy', favourited);
  await stubEntryStateWrites(page);
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.getByRole('link', { name: 'Favorites' }).click();

  const slots = page.locator('.rows.magazine .magazine-slot');
  const first = slots.nth(0);
  const newFirst = slots.nth(1);
  await expect(first).toBeVisible();

  await first.getByRole('button', { name: 'Favorite', exact: true }).click();
  await expect(first, 'un-favouriting the first entry should mark it leaving').toHaveClass(
    /leaving/,
  );

  const inner = newFirst.locator('.row-slot-inner');
  await expect(
    inner.evaluate((el) => getComputedStyle(el).borderTopWidth),
    'the new first block must not draw a rule above it',
  ).resolves.toBe('0px');
  await expect(
    inner.evaluate((el) => getComputedStyle(el).paddingTop),
    'the new first block must not reserve the rule padding above it',
  ).resolves.toBe('0px');
});

/**
 * A reduced-motion viewer should not sit through the 260ms rule-close: the
 * override selector must beat the shorthand `animation` rule that re-sets
 * the duration, not just share its (dead) specificity.
 */
test('reduced motion collapses the airy leaving slot in ~1ms, not 260ms', async ({ page }) => {
  const favourited = ENTRIES.map((e) => ({ ...e, isFavorite: true }));
  await stubAccount(page, 'airy', favourited);
  await stubEntryStateWrites(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.getByRole('link', { name: 'Favorites' }).click();

  // Not the first slot: that one carries no rule to close in the first place.
  const slot = page.locator('.rows.magazine .magazine-slot').nth(1);
  await expect(slot).toBeVisible();
  await slot.getByRole('button', { name: 'Favorite', exact: true }).click();
  await expect(slot, 'un-favouriting should drop the row out of the Favorites view').toHaveClass(
    /leaving/,
  );

  const inner = slot.locator('.row-slot-inner');
  await expect(
    inner.evaluate((el) => getComputedStyle(el).animationDuration),
    'the rule-close animation must honour reduced motion, not the 0.26s shorthand',
  ).resolves.toBe('0.001s');

  await expect
    .poll(() => slot.evaluate((el) => Math.round(el.getBoundingClientRect().height)), {
      timeout: 500,
    })
    .toBe(0);
});
