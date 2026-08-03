// e2e/magazine-kicker-one-line.spec.ts
import { test, expect, Page } from '@playwright/test';

// The seeded e2e admin, as in `magazine-smoke.spec.ts`.
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

async function signInAsAdmin(page: Page): Promise<boolean> {
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
 * The kicker line must occupy exactly one line in every magazine block, however
 * long the feed's title is — a five-clause title like "NDR.de - Das Beste am
 * Norden - Radio - Fernsehen - Nachrichten" used to push the time onto a third
 * line (#155). Measured, not eyeballed: the rendered row is compared against a
 * single line's height, and the source is asserted to be the part that clips.
 */
test('the kicker line never wraps, at any viewport', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  for (const viewport of [
    { width: 1280, height: 900 },
    { width: 768, height: 1024 },
    { width: 375, height: 812 },
  ]) {
    await page.setViewportSize(viewport);

    const lines = page.locator('app-entry-kicker-line .kicker');
    await expect(lines.first()).toBeVisible();

    const overflowing = await lines.evaluateAll((rows) =>
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

    expect(
      overflowing,
      `kicker lines wrapped at ${viewport.width}x${viewport.height}`,
    ).toBe(0);
  }
});

test('a long source never widens the page', async ({ page }) => {
  const signedIn = await signInAsAdmin(page);
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.setViewportSize({ width: 375, height: 812 });
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
  test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin against the stack)');

  await page.setViewportSize({ width: 375, height: 812 });

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
