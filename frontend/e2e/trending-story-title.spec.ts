import { expect, Page, test } from '@playwright/test';
import { signInWithLayout } from './support/auth';
import { entryWire } from './support/reader';
import type { TrendingResponseDto } from '../src/app/reader/trending/trending-page';
import type {
  FollowedStoriesResponseDto,
  StoryPageDto,
} from '../src/app/reader/trending/trending.models';

/**
 * Stubs the Trending list, the followed list and the story's entries, so the spec owns the title it
 * asserts on (#2329): the real one is written by whatever LLM the stack is configured with.
 */
const TITLE = 'Hafenarbeiter in Hamburg streiken';
const STORY_ID = 601;
const LEAD = entryWire({ id: 9101, title: 'Fixture lead article', source: 'Fixture lead' });

function trendingWith(followed: boolean): TrendingResponseDto {
  return {
    window: 'day',
    builtAt: '2026-11-18T08:00:00Z',
    stories: [
      {
        id: STORY_ID,
        title: TITLE,
        followed,
        outletCount: 1,
        viewed: false,
        shareCount: 0,
        leadEntryId: LEAD.id,
        articles: [{ ...LEAD, shares: [] }],
      },
    ],
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

function storyPage(followed: boolean): StoryPageDto {
  return {
    entries: [LEAD],
    nextCursor: null,
    title: TITLE,
    lastOpenedAt: followed ? '2026-11-18T06:00:00Z' : null,
    newEntryIds: [],
  };
}

async function stubStory(page: Page, followed: boolean): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/trending',
    (route) => route.fulfill({ status: 200, json: trendingWith(followed) }),
  );
  await page.route(
    (url) => url.pathname === '/api/trending/followed',
    (route) => route.fulfill({ status: 200, json: followedWith(followed) }),
  );
  await page.route(
    (url) => url.pathname === `/api/trending/stories/${STORY_ID}/opened`,
    (route) => route.fulfill({ status: 204 }),
  );
  await page.route(
    (url) => url.pathname === `/api/entries/trending-stories/${STORY_ID}`,
    (route) => route.fulfill({ status: 200, json: storyPage(followed) }),
  );
}

async function openTrending(page: Page): Promise<void> {
  await page
    .getByRole('navigation', { name: 'Feeds' })
    .getByRole('link', { name: /^Trending\b/ })
    .click();
  await expect(page.locator('app-story-title .title')).toHaveText(TITLE);
}

const heading = (page: Page) => page.locator('app-list-header h2').first();

for (const layout of ['magazine', 'list'] as const) {
  test(`a Trending story shows its own title, in the card, the sidebar and the opened story (${layout})`, async ({
    page,
  }) => {
    await stubStory(page, true);
    test.skip(
      !(await signInWithLayout(page, layout)),
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await openTrending(page);

    await page.getByRole('button', { name: 'Show or hide followed stories' }).click();
    const row = page.locator('.followed-row .name', { hasText: TITLE });
    await expect(row).toBeVisible();
    await row.click();
    await expect(heading(page)).toContainText(TITLE);
  });

  test(`a story's title opens its list, followed or not (${layout})`, async ({ page }) => {
    await stubStory(page, false);
    test.skip(
      !(await signInWithLayout(page, layout)),
      'seeded admin login unavailable (run app:e2e:seed-admin against the stack)',
    );

    await openTrending(page);
    await page.getByRole('link', { name: TITLE }).click();

    await expect(page).toHaveURL(new RegExp(`/trending/stories/${STORY_ID}$`));
    await expect(heading(page)).toContainText(TITLE);
    await expect(page.getByRole('button', { name: 'Follow story' })).toBeVisible();
    await expect(page.locator('.followed-row.active')).toHaveCount(0);
  });
}
