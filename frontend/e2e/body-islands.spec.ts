import { test, expect, Page } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryDetailJson, entryWire, readerFailedJson } from './support/reader';

const DESKTOP = { width: 1280, height: 800 };
const TITLE = 'Entry with body islands';

const slide = (fill: string) =>
  `<li><img alt="" width="40" height="30" src="data:image/svg+xml,${encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="30"><rect width="40" height="30" fill="${fill}"/></svg>`,
  )}"></li>`;

const BODY = [
  '<audio class="reader-narration" controls src="data:audio/mpeg;base64,"></audio>',
  '<p>Opening paragraph of the article.</p>',
  `<figure class="reader-slideshow"><ol>${slide('red')}${slide('green')}${slide('blue')}</ol></figure>`,
].join('');

const entry = entryWire({
  id: 1,
  title: TITLE,
  url: 'https://example.invalid/1',
  summary: 'summary',
  excerpt: 'summary',
  publishedAt: '2026-07-25T10:00:00Z',
  createdAt: '2026-07-25T10:00:00Z',
  subscriptionId: 5,
  source: 'stub',
});

async function openArticle(page: Page) {
  await page.route('**/api/entries/*/reader', async (route) =>
    route.fulfill({ status: 200, json: readerFailedJson() }),
  );
  await page.route('**/api/entries/*/state', async (route) => {
    const patch = (route.request().postDataJSON() ?? {}) as Record<string, boolean>;
    await route.fulfill({
      status: 200,
      json: {
        state: {
          entryId: 1,
          isHidden: false,
          isFavorite: false,
          isKept: false,
          hiddenAt: null,
          ...patch,
        },
      },
    });
  });
  await page.route('**/api/entries/1', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: entryDetailJson(entry, BODY) });
  });
  await page.route('**/api/entries*', async (route) => {
    if (route.request().method() !== 'GET') return route.fallback();
    await route.fulfill({ status: 200, json: { entries: [entry], nextCursor: null } });
  });
  await page.reload();
  await page.getByText(TITLE, { exact: false }).first().click();
  const pane = page.locator('app-reader-view');
  await expect(pane.locator('.content p').first()).toBeVisible();
  return pane;
}

test.describe('Islands mounted into the article body', () => {
  test.use({ viewport: DESKTOP });

  test.beforeEach(async ({ page }) => {
    const signedIn = await signInWithLayout(page, 'list');
    test.skip(!signedIn, 'seeded admin login unavailable (run app:e2e:seed-admin)');
  });

  test('steps a body slideshow with its controls and the arrow keys', async ({ page }) => {
    const pane = await openArticle(page);
    const figure = pane.locator('figure.reader-slideshow');
    const counter = figure.locator('.reader-slideshow__counter');
    const track = figure.locator('ol');
    await expect(counter).toHaveText('1 of 3');

    await figure.locator('.reader-slideshow__next').click();
    await expect(counter).toHaveText('2 of 3');
    await expect(track).toHaveAttribute('style', /translateX\(-100%\)/);
    const items = figure.locator('ol > li');
    await expect(items.nth(0)).toHaveAttribute('inert', '');
    await expect(items.nth(1)).not.toHaveAttribute('inert');
    await expect(items.nth(2)).toHaveAttribute('inert', '');

    await figure.focus();
    await page.keyboard.press('ArrowRight');
    await expect(counter).toHaveText('3 of 3');
  });

  test('keeps a narration player behind a quiet toggle', async ({ page }) => {
    const pane = await openArticle(page);
    const toggle = pane.locator('app-narration-toggle');
    const audio = pane.locator('audio.reader-narration');
    await expect(audio).toBeHidden();
    expect(
      await toggle.evaluate((host) => host.nextElementSibling?.matches('audio.reader-narration')),
    ).toBe(true);

    const toggleBox = (await toggle.boundingBox())!;
    const paragraphBox = (await pane.locator('.content p').first().boundingBox())!;
    const space3 = await page.evaluate(() => {
      const probe = document.createElement('div');
      probe.style.height = 'var(--space-3)';
      document.body.appendChild(probe);
      const height = probe.getBoundingClientRect().height;
      probe.remove();
      return height;
    });
    const gap = paragraphBox.y - (toggleBox.y + toggleBox.height);
    expect(gap).toBeGreaterThanOrEqual(space3 - 0.5);

    await toggle.getByRole('button').click();
    await expect(audio).toBeVisible();
    await toggle.getByRole('button').click();
    await expect(audio).toBeHidden();

    const sizes = await page.evaluate(() => {
      const button = document.querySelector('app-narration-toggle button') as HTMLElement;
      const paragraph = document.querySelector('app-reader-view .content p') as HTMLElement;
      return {
        toggle: parseFloat(getComputedStyle(button).fontSize),
        paragraph: parseFloat(getComputedStyle(paragraph).fontSize),
      };
    });
    expect(sizes.toggle).toBeLessThan(sizes.paragraph);
  });
});
