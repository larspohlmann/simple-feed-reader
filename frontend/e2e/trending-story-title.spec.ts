import { expect, Page, test } from '@playwright/test';
import { nextFrames, settleAnimations } from './support/animations';
import { signInWithLayout } from './support/auth';
import { entryWire } from './support/reader';
import type { TrendingResponseDto } from '../src/app/reader/trending/trending-page';
import type {
  FollowedStoriesResponseDto,
  StoryPageDto,
} from '../src/app/reader/trending/trending.models';

/**
 * Stubs the Trending list, the followed list and the stories' entries, so the spec owns the titles it
 * asserts on (#2329): the real ones are written by whatever LLM the stack is configured with.
 */
const TITLE = 'Hafenarbeiter in Hamburg streiken';
const STORY_ID = 601;
const STORY_IDS = [601, 602, 603, 604, 605, 606, 607, 608];
const titleOf = (id: number) => (id === STORY_ID ? TITLE : `Fixture story ${id}`);
const leadOf = (id: number) =>
  entryWire({ id: id * 10, title: `Fixture lead article ${id}`, source: `Fixture lead ${id}` });

function trendingWith(followed: boolean): TrendingResponseDto {
  return {
    window: 'day',
    builtAt: '2026-11-18T08:00:00Z',
    stories: STORY_IDS.map((id) => ({
      id,
      title: titleOf(id),
      followed: followed && id === STORY_ID,
      outletCount: 1,
      viewed: false,
      shareCount: 0,
      leadEntryId: leadOf(id).id,
      articles: [{ ...leadOf(id), shares: [] }],
    })),
  };
}

function followedWith(followed: boolean): FollowedStoriesResponseDto {
  const story = {
    id: STORY_ID,
    headline: TITLE,
    newCount: 0,
    quiet: false,
    lastJoinedAt: '2026-11-18T07:00:00Z',
  };
  return { stories: followed ? [story] : [], nextBuildDueAt: null };
}

function storyPage(id: number, followed: boolean): StoryPageDto {
  return {
    entries: [leadOf(id)],
    nextCursor: null,
    title: titleOf(id),
    lastOpenedAt: followed ? '2026-11-18T06:00:00Z' : null,
    newEntryIds: [],
  };
}

interface Stubbed {
  readonly trendingRequests: () => number;
}

async function stubStories(page: Page, followed: boolean): Promise<Stubbed> {
  let trendingRequests = 0;
  await page.route(
    (url) => url.pathname === '/api/trending',
    (route) => {
      trendingRequests++;
      return route.fulfill({ status: 200, json: trendingWith(followed) });
    },
  );
  await page.route(
    (url) => url.pathname === '/api/trending/followed',
    (route) => route.fulfill({ status: 200, json: followedWith(followed) }),
  );
  await page.route(
    (url) => /^\/api\/trending\/stories\/\d+\/opened$/.test(url.pathname),
    (route) => route.fulfill({ status: 204 }),
  );
  await page.route(
    (url) => /^\/api\/entries\/trending-stories\/\d+$/.test(url.pathname),
    (route) => {
      const id = Number(new URL(route.request().url()).pathname.split('/').pop());
      return route.fulfill({ status: 200, json: storyPage(id, followed) });
    },
  );
  return { trendingRequests: () => trendingRequests };
}

async function signIn(page: Page, layout: 'magazine' | 'list'): Promise<void> {
  test.skip(
    !(await signInWithLayout(page, layout)),
    'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
  );
}

async function openTrending(page: Page): Promise<void> {
  await page.goto('/?view=trending&window=day');
  await expect(page.locator('app-story-title .title').first()).toHaveText(TITLE);
}

const layer = (page: Page) => page.locator('app-story-layer');
const closeStory = (page: Page) => layer(page).locator('.bar .close').click();

for (const layout of ['magazine', 'list'] as const) {
  for (const [device, viewport] of [
    ['desktop', { width: 1280, height: 900 }],
    ['phone', { width: 375, height: 667 }],
  ] as const) {
    test.describe(`${layout}, ${device}`, () => {
      test.use({ viewport });

      test("a story's title opens its list over Trending; back leaves Trending as it was", async ({
        page,
      }) => {
        const stubbed = await stubStories(page, false);
        await signIn(page, layout);
        await openTrending(page);
        const card = page.locator('app-story-title').nth(5);
        await card.scrollIntoViewIfNeeded();
        await page.evaluate(() => window.scrollBy(0, 40));
        await nextFrames(page);
        const before = {
          scrollY: await page.evaluate(() => window.scrollY),
          top: (await card.boundingBox())!.y,
        };
        const requests = stubbed.trendingRequests();

        await card.locator('a').click();
        await expect(page).toHaveURL(/\/trending\/stories\/606\?view=trending&window=day$/);
        await expect(layer(page)).toContainText('Fixture story 606');
        await expect(layer(page).getByRole('button', { name: 'Follow story' })).toBeVisible();
        await closeStory(page);
        await expect(layer(page)).toHaveCount(0);
        await nextFrames(page);

        expect(stubbed.trendingRequests()).toBe(requests);
        expect(await page.locator('app-loading-overlay.shown').count()).toBe(0);
        expect(
          Math.abs((await page.evaluate(() => window.scrollY)) - before.scrollY),
        ).toBeLessThanOrEqual(2);
        expect(Math.abs((await card.boundingBox())!.y - before.top)).toBeLessThanOrEqual(2);
      });
    });
  }

  test(`a followed story's sidebar row opens its list over the list on screen (${layout})`, async ({
    page,
  }) => {
    await stubStories(page, true);
    await signIn(page, layout);
    await page.goto('/?view=kept');

    await page.getByRole('button', { name: 'Show or hide followed stories' }).click();
    await page.locator('.followed-row .name', { hasText: TITLE }).click();

    await expect(page).toHaveURL(new RegExp(`/trending/stories/${STORY_ID}\\?view=kept$`));
    await expect(layer(page)).toContainText(TITLE);
    await expect(layer(page).getByRole('button', { name: 'Unfollow story' })).toBeVisible();
    await closeStory(page);
    await expect(page).toHaveURL(/\/\?view=kept$/);
  });

  test(`a deep link opens the story over the start list, with a working back (${layout})`, async ({
    page,
  }) => {
    await stubStories(page, false);
    await signIn(page, layout);

    await page.goto(`/trending/stories/${STORY_ID}`);
    await expect(layer(page)).toContainText(TITLE);
    await closeStory(page);

    await expect(layer(page)).toHaveCount(0);
    await expect(page).toHaveURL(/\/$/);
  });
}

test.describe("a story's list loads in its own column (magazine, desktop)", () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('its placeholders stand where its cards land', async ({ page }) => {
    await stubStories(page, false);
    let release: () => void = () => undefined;
    const held = new Promise<void>((resolve) => (release = resolve));
    await page.route(
      (url) => url.pathname === `/api/entries/trending-stories/${STORY_ID}`,
      async (route) => {
        await held;
        return route.fulfill({ status: 200, json: storyPage(STORY_ID, false) });
      },
    );
    await signIn(page, 'magazine');
    await openTrending(page);

    await page.getByRole('link', { name: TITLE }).click();
    await layer(page).locator('.skeleton').first().waitFor();
    await settleAnimations(page.locator('.story-overlay'));
    const placeholder = await layer(page).locator('.skeleton').first().boundingBox();
    release();
    const card = layer(page).locator('.rows.magazine > .magazine-slot').first();
    await expect(card).toBeVisible();
    const landed = await card.boundingBox();

    expect(Math.abs(placeholder!.x - landed!.x)).toBeLessThanOrEqual(2);
    expect(Math.abs(placeholder!.width - landed!.width)).toBeLessThanOrEqual(2);
  });
});

declare global {
  interface Window {
    storyAnimations: string[];
  }
}

test.describe("a story's list slides like an article (phone)", () => {
  test.use({ viewport: { width: 375, height: 667 } });

  for (const reducedMotion of ['no-preference', 'reduce'] as const) {
    test(`slides in from its title and out through its back button (${reducedMotion})`, async ({
      page,
    }) => {
      await page.emulateMedia({ reducedMotion });
      await stubStories(page, false);
      await signIn(page, 'magazine');
      await openTrending(page);

      await page.getByRole('link', { name: TITLE }).click();
      const overlay = page.locator('.story-overlay');
      const entered = await overlay.evaluate((element) => getComputedStyle(element).animationName);
      await settleAnimations(overlay);
      await page.evaluate(() => {
        window.storyAnimations = [];
        const leaving = new Set<Element>();
        new MutationObserver((records) => {
          for (const record of records) {
            const target = record.target as HTMLElement;
            if (!leaving.has(target) && target.matches('.story-overlay.article-leave')) {
              leaving.add(target);
              window.storyAnimations.push(getComputedStyle(target).animationName);
            }
          }
        }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
      });
      await closeStory(page);
      await expect(overlay).toHaveCount(0);

      const left = await page.evaluate(() => window.storyAnimations);
      if (reducedMotion === 'reduce') {
        expect(entered).toBe('none');
        expect(left).toEqual(['none']);
      } else {
        expect(entered).toMatch(/article-enter$/);
        expect(left).toEqual([expect.stringMatching(/article-leave$/)]);
      }
    });
  }
});
