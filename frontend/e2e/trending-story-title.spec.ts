import { expect, Page, test } from '@playwright/test';
import { nextFrames, settleAnimations } from './support/animations';
import { signInWithLayout } from './support/auth';
import { entryWire } from './support/reader';
import { liftTouch, touchDrag } from './support/scroll';
import type { EntryDto } from '../src/app/reader/models';
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
const SYNOPSIS =
  'Die Hafenarbeiter in Hamburg haben am Morgen die Arbeit niedergelegt. Der Containerumschlag steht still.';
const FALLBACK_ID = 602;
const titleOf = (id: number) => (id === STORY_ID ? TITLE : `Fixture story ${id}`);
const synopsisOf = (id: number) => (id === STORY_ID ? SYNOPSIS : null);
const leadOf = (id: number) =>
  entryWire({ id: id * 10, title: `Fixture lead article ${id}`, source: `Fixture lead ${id}` });

function trendingWith(followed: boolean): TrendingResponseDto {
  return {
    window: 'day',
    builtAt: '2026-11-18T08:00:00Z',
    stories: STORY_IDS.map((id) => ({
      id,
      title: titleOf(id),
      synopsis: synopsisOf(id),
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

function storyPage(id: number, followed: boolean, over: Partial<StoryPageDto> = {}): StoryPageDto {
  return {
    entries: [leadOf(id)],
    nextCursor: null,
    title: titleOf(id),
    synopsis: synopsisOf(id),
    memberCount: 1,
    unreadCount: 1,
    lastOpenedAt: followed ? '2026-11-18T06:00:00Z' : null,
    newEntryIds: [],
    ...over,
  };
}

/** The story's own page, over the stubbed one, with `rows` and the tally the server would count. */
async function stubStoryPage(page: Page, over: Partial<StoryPageDto>): Promise<void> {
  await page.route(
    (url) => url.pathname === `/api/entries/trending-stories/${STORY_ID}`,
    (route) => route.fulfill({ status: 200, json: storyPage(STORY_ID, false, over) }),
  );
}

/** Kept, owned by the spec: `entries`, so nothing beneath the story is the seeded account's. */
async function stubKept(page: Page, entries: EntryDto[] = []): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/entries' && url.searchParams.get('view') === 'kept',
    (route) => route.fulfill({ status: 200, json: { entries, nextCursor: null } }),
  );
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

async function signIn(page: Page, layout: 'magazine' | 'list' | 'pane'): Promise<void> {
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
const closeStory = (page: Page) => layer(page).locator('.story-back').click();

/** Opens the titled story from Trending, its page `over` the stubbed one; resolves once its list shows. */
async function openStoryFromTrending(
  page: Page,
  layout: 'magazine' | 'list' | 'pane',
  over?: Partial<StoryPageDto>,
): Promise<Stubbed> {
  const stubbed = await stubStories(page, false);
  if (over) await stubStoryPage(page, over);
  await signIn(page, layout);
  await openTrending(page);
  await page.getByRole('link', { name: TITLE }).click();
  await expect(layer(page)).toContainText(TITLE);
  return stubbed;
}

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

for (const [device, viewport] of [
  ['desktop', { width: 1280, height: 900 }],
  ['phone', { width: 375, height: 667 }],
] as const) {
  test.describe(`a story's synopsis (#2353, ${device})`, () => {
    test.use({ viewport });

    test('shows beneath the title on the card and at the top of its list', async ({ page }) => {
      await openStoryFromTrending(page, 'magazine');

      await expect(layer(page).locator('p.synopsis')).toHaveText(SYNOPSIS);
      await closeStory(page);
      const card = page.locator('app-story-title', { hasText: TITLE });
      await expect(card.locator('h3.title + p.synopsis')).toHaveText(SYNOPSIS);
    });

    test('a story without one shows none, on its card or in its list', async ({ page }) => {
      await stubStories(page, false);
      await signIn(page, 'magazine');
      await openTrending(page);
      const card = page.locator('app-story-title', { hasText: titleOf(FALLBACK_ID) });
      await expect(card.locator('h3.title')).toBeVisible();
      await expect(card.locator('.synopsis')).toHaveCount(0);

      await card.locator('a').click();
      await expect(layer(page)).toContainText(titleOf(FALLBACK_ID));
      await expect(layer(page).locator('p.synopsis')).toHaveCount(0);
    });
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

test.describe("a story's list reads like any list (desktop)", () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  test('marks the story read and shows only its unread articles', async ({ page }) => {
    const marked: unknown[] = [];
    await page.route(
      (url) => url.pathname === `/api/entries/trending-stories/${STORY_ID}/mark-read`,
      (route) => {
        marked.push(route.request().postDataJSON());
        return route.fulfill({ status: 204 });
      },
    );
    await openStoryFromTrending(page, 'list');
    const header = layer(page).locator('app-list-header');
    await expect(header.locator('h2')).toContainText(TITLE);
    await expect(header.locator('.title-count')).toHaveText('1');

    const unreadOnly = page.waitForRequest(
      (request) =>
        request.url().includes(`/api/entries/trending-stories/${STORY_ID}`) &&
        new URL(request.url()).searchParams.get('unread') === '1',
    );
    await header.getByRole('switch', { name: 'Unread' }).click();
    await unreadOnly;
    await header.getByRole('switch', { name: 'Unread' }).click();

    await header.getByRole('button', { name: 'Mark all read' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Mark all read' }).click();

    await expect.poll(() => marked.length).toBe(1);
    expect(marked[0]).toEqual({ until: expect.any(String) });
  });

  test("shows the list's own corner buttons, and none of the article's", async ({ page }) => {
    await openStoryFromTrending(page, 'magazine');

    await expect(page.locator('.story-overlay app-article-layer')).toHaveCount(0);
    await expect(page.locator('.story-overlay .corner-back')).toHaveCount(0);
  });
});

declare global {
  interface Window {
    storyAnimations: string[];
  }
}

/** The animation the story's overlay leaves with, through `close`; in the split layout, its list's inside it. */
async function leaveAnimation(
  page: Page,
  close: () => Promise<void>,
  moving = '.story-overlay',
): Promise<string[]> {
  const overlay = page.locator('.story-overlay');
  await settleAnimations(overlay);
  await page.evaluate((movingSelector) => {
    window.storyAnimations = [];
    const leaving = new Set<Element>();
    new MutationObserver((records) => {
      for (const record of records) {
        const target = record.target as HTMLElement;
        if (!leaving.has(target) && target.matches('.story-overlay.article-leave')) {
          leaving.add(target);
          const slides = target.matches(movingSelector)
            ? target
            : target.querySelector(movingSelector)!;
          window.storyAnimations.push(getComputedStyle(slides).animationName);
        }
      }
    }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
  }, moving);
  await close();
  await expect(overlay).toHaveCount(0);
  return page.evaluate(() => window.storyAnimations);
}

/** Checks how the story slides in and, through its back button, out: `sliding` is what moves. */
async function expectSlides(
  page: Page,
  reducedMotion: 'no-preference' | 'reduce',
  sliding = '.story-overlay',
): Promise<void> {
  const entered = await page
    .locator(sliding)
    .evaluate((element) => getComputedStyle(element).animationName);
  const left = await leaveAnimation(page, () => closeStory(page), sliding);

  if (reducedMotion === 'reduce') {
    expect(entered).toBe('none');
    expect(left).toEqual(['none']);
  } else {
    expect(entered).toMatch(/article-enter$/);
    expect(left).toEqual([expect.stringMatching(/article-leave$/)]);
  }
}

test.describe("a story's list slides like an article (phone)", () => {
  test.use({ viewport: { width: 375, height: 667 } });

  for (const reducedMotion of ['no-preference', 'reduce'] as const) {
    test(`slides in from its title and out through its back button (${reducedMotion})`, async ({
      page,
    }) => {
      await page.emulateMedia({ reducedMotion });
      await openStoryFromTrending(page, 'magazine');

      await expectSlides(page, reducedMotion);
    });
  }
});

test.describe("a story's list slides inside the list column in the split layout", () => {
  test.use({ viewport: { width: 1440, height: 900 } });

  for (const reducedMotion of ['no-preference', 'reduce'] as const) {
    test(`slides in and out within its column, never over the reader pane (${reducedMotion})`, async ({
      page,
    }) => {
      await page.emulateMedia({ reducedMotion });
      await openStoryFromTrending(page, 'pane');

      const clip = await page.locator('.main.split > .story-overlay').evaluate((element) => {
        const reader = document.querySelector('.main.split .reader')!.getBoundingClientRect();
        return {
          overflow: getComputedStyle(element).overflowX,
          gap: reader.left - element.getBoundingClientRect().right,
        };
      });

      expect(clip.overflow).toBe('clip');
      expect(clip.gap).toBeGreaterThanOrEqual(-1);
      await expectSlides(page, reducedMotion, '.story-overlay > app-story-layer');
    });
  }
});

test.describe("a story's list is full screen on a phone, as an article is", () => {
  test.use({ viewport: { width: 375, height: 667 } });

  test('its header starts at the top of the screen, over the app bar', async ({ page }) => {
    await openStoryFromTrending(page, 'magazine');
    await settleAnimations(page.locator('.story-overlay'));

    const header = await layer(page)
      .locator('.story-back')
      .evaluate((back) => {
        const bar = back.closest('.list-header') ?? back.parentElement!;
        return {
          top: bar.getBoundingClientRect().top,
          backTop: back.getBoundingClientRect().top,
          topmost: document.elementFromPoint(187, 4)?.closest('app-story-layer') !== null,
        };
      });
    expect(header.top).toBeLessThanOrEqual(1);
    expect(header.backTop).toBeLessThan(40);
    expect(header.topmost).toBe(true);
  });
});

for (const [device, viewport, shown] of [
  ['phone', { width: 375, height: 667 }, false],
  ['desktop', { width: 1280, height: 900 }, true],
] as const) {
  test.describe(`the story's glyph in its header (${device})`, () => {
    test.use({ viewport });

    test(`${shown ? 'leads' : 'gives its room to'} the title`, async ({ page }) => {
      await openStoryFromTrending(page, 'magazine');
      const header = layer(page).locator('app-list-header');
      await expect(header.locator('h2')).toContainText(TITLE);

      await expect(header.locator('.story-glyph')).toBeVisible({ visible: shown });
    });
  });
}

test.describe("a story's list goes back on the article's swipe (phone)", () => {
  test.use({ viewport: { width: 375, height: 667 }, hasTouch: true, isMobile: true });

  for (const reducedMotion of ['no-preference', 'reduce'] as const) {
    test(`a rightward swipe takes the story off, Trending as it was (${reducedMotion})`, async ({
      page,
    }) => {
      await page.emulateMedia({ reducedMotion });
      const stubbed = await openStoryFromTrending(page, 'magazine');
      const requests = stubbed.trendingRequests();
      await settleAnimations(page.locator('.story-overlay'));

      await touchDrag(page, 'app-story-layer', { x: 20, y: 400 }, { x: 260, y: 410 });
      await expect(layer(page)).toHaveAttribute('style', /translate3d\(240px/);
      await liftTouch(page, 'app-story-layer');

      await expect(layer(page)).toHaveCount(0);
      await expect(page).toHaveURL(/\/\?view=trending&window=day$/);
      expect(stubbed.trendingRequests()).toBe(requests);
    });
  }
});

test.describe("a story's list is a list of its own (desktop)", () => {
  test.use({ viewport: { width: 1280, height: 900 } });

  const keptLead: EntryDto = { ...leadOf(STORY_ID), isKept: true };
  const keepButton = (page: Page) =>
    layer(page).locator('.row-slot').first().getByRole('button', { name: 'Keep', exact: true });

  async function openOverKept(page: Page): Promise<void> {
    await stubStories(page, false);
    await stubStoryPage(page, { entries: [keptLead] });
    await stubKept(page);
    await signIn(page, 'list');
    await page.goto(`/trending/stories/${STORY_ID}?view=kept`);
    await expect(layer(page).locator('.row-slot')).toHaveCount(1);
  }

  test('a row un-kept in a story over Kept stays in the story', async ({ page }) => {
    await page.route(
      (url) => url.pathname === `/api/entries/${keptLead.id}/state`,
      (route) => route.fulfill({ status: 200, json: {} }),
    );
    await openOverKept(page);
    const saved = page.waitForRequest((request) => request.url().includes('/state'));

    await layer(page).locator('.row-slot').first().hover();
    await keepButton(page).click();
    await saved;

    await expect(layer(page).locator('.row-slot')).toHaveCount(1);
    await expect(layer(page).locator('.row-slot.leaving')).toHaveCount(0);
  });

  test('a favourite and a read in the story show on the same row beneath it, with no reload', async ({
    page,
  }) => {
    let keptRequests = 0;
    await page.route(
      (url) => url.pathname === `/api/entries/${keptLead.id}/state`,
      (route) => route.fulfill({ status: 200, json: {} }),
    );
    await stubStories(page, false);
    await stubStoryPage(page, { entries: [keptLead] });
    await page.route(
      (url) => url.pathname === '/api/entries' && url.searchParams.get('view') === 'kept',
      (route) => {
        keptRequests++;
        return route.fulfill({ status: 200, json: { entries: [keptLead], nextCursor: null } });
      },
    );
    await signIn(page, 'list');
    await page.goto(`/trending/stories/${STORY_ID}?view=kept`);
    const storyRow = layer(page).locator('.row-slot').first();
    await expect(storyRow).toHaveCount(1);
    await expect(page.locator('section.list .row-slot')).toHaveCount(1);
    const loads = keptRequests;

    await storyRow.hover();
    await storyRow.getByRole('button', { name: 'Favorite', exact: true }).click();
    await storyRow.getByRole('button', { name: 'Toggle read', exact: true }).click();
    await closeStory(page);
    await expect(layer(page)).toHaveCount(0);

    const beneath = page.locator('section.list .row-slot').first();
    await expect(beneath.getByRole('button', { name: 'Favorite', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    await expect(beneath.getByRole('button', { name: 'Toggle read', exact: true })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    expect(keptRequests).toBe(loads);
  });

  test('mark all read in the story marks only its rows, so the rest of the list beneath stays unread', async ({
    page,
  }) => {
    const shared: EntryDto = { ...keptLead, isHidden: false };
    const onlyBeneath = entryWire({ id: 992, title: 'Fixture row only beneath', isKept: true });
    let markedOnServer = false;
    let keptRequests = 0;
    const markReads: string[] = [];
    await stubStories(page, false);
    await page.route(
      (url) => url.pathname === `/api/entries/trending-stories/${STORY_ID}`,
      (route) =>
        route.fulfill({
          status: 200,
          json: storyPage(STORY_ID, false, {
            entries: [{ ...shared, isHidden: markedOnServer }],
          }),
        }),
    );
    await page.route(
      (url) => url.pathname === '/api/entries' && url.searchParams.get('view') === 'kept',
      (route) => {
        keptRequests++;
        const entries = [{ ...shared, isHidden: markedOnServer }, onlyBeneath];
        return route.fulfill({ status: 200, json: { entries, nextCursor: null } });
      },
    );
    await page.route(
      (url) => url.pathname.endsWith('/mark-read'),
      (route) => {
        markReads.push(new URL(route.request().url()).pathname);
        markedOnServer = true;
        return route.fulfill({ status: 204 });
      },
    );
    await signIn(page, 'list');
    await page.goto(`/trending/stories/${STORY_ID}?view=kept`);
    await expect(layer(page).locator('.row-slot')).toHaveCount(1);
    const beneath = (title: string) =>
      page.locator('section.list .row-slot', { hasText: title }).locator('.dot');
    await expect(beneath(shared.title)).toHaveClass(/\bon\b/);
    await expect(beneath(onlyBeneath.title)).toHaveClass(/\bon\b/);

    const header = layer(page).locator('app-list-header');
    await header.getByRole('button', { name: 'Mark all read' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Mark all read' }).click();
    await expect
      .poll(() => markReads)
      .toEqual([`/api/entries/trending-stories/${STORY_ID}/mark-read`]);
    await closeStory(page);
    await expect(layer(page)).toHaveCount(0);

    await expect(beneath(shared.title)).not.toHaveClass(/\bon\b/);
    await expect(beneath(onlyBeneath.title)).toHaveClass(/\bon\b/);
    const loads = keptRequests;
    await page.reload();
    await expect.poll(() => keptRequests).toBeGreaterThan(loads);
    await expect(beneath(onlyBeneath.title)).toHaveClass(/\bon\b/);
    await expect(beneath(shared.title)).not.toHaveClass(/\bon\b/);
  });

  test("a failed change shows on the story's banner, not the list's beneath", async ({ page }) => {
    await page.route(
      (url) => url.pathname === `/api/entries/${keptLead.id}/state`,
      (route) =>
        route.fulfill({
          status: 500,
          contentType: 'application/problem+json',
          json: { type: 'about:blank', title: 'Server error', status: 500 },
        }),
    );
    await openOverKept(page);

    await layer(page).locator('.row-slot').first().hover();
    await keepButton(page).click();

    await expect(layer(page).locator('app-error-banner')).toBeVisible();
    await expect(page.locator('section.list app-error-banner')).toHaveCount(0);
  });

  test("the count pill reads the server's tally of a story longer than a page", async ({
    page,
  }) => {
    await openStoryFromTrending(page, 'list', {
      nextCursor: 'next-page',
      memberCount: 31,
      unreadCount: 12,
    });
    const header = layer(page).locator('app-list-header');
    await expect(header.locator('.title-count')).toHaveText('31');
    await header.getByRole('switch', { name: 'Unread' }).click();
    await expect(header.locator('.title-count')).toHaveText('12');
    await header.getByRole('switch', { name: 'Unread' }).click();
  });

  test('a click on the list beneath while the story slides out reaches that list', async ({
    page,
  }) => {
    const keptRow = entryWire({ id: 991, title: 'Fixture kept row', isKept: true });
    const patched: string[] = [];
    await page.route(
      (url) => /^\/api\/entries\/\d+\/state$/.test(url.pathname),
      (route) => {
        patched.push(new URL(route.request().url()).pathname);
        return route.fulfill({ status: 200, json: {} });
      },
    );
    await stubStories(page, false);
    await stubStoryPage(page, { entries: [keptLead] });
    await stubKept(page, [keptRow]);
    await signIn(page, 'list');
    await page.goto(`/trending/stories/${STORY_ID}?view=kept`);
    await expect(layer(page).locator('.row-slot')).toHaveCount(1);
    await expect(page.locator('section.list .row-slot')).toHaveCount(1);
    await settleAnimations(page.locator('.story-overlay'));

    await closeStory(page);
    await page.locator('.story-overlay.article-leave').waitFor({ state: 'attached' });
    await page
      .locator('section.list .row-slot')
      .first()
      .getByRole('button', { name: 'Favorite', exact: true })
      .dispatchEvent('click');

    await expect.poll(() => patched).toEqual([`/api/entries/${keptRow.id}/state`]);
  });

  test("hiding the sidebar focuses the story's own way back to it", async ({ page }) => {
    await openStoryFromTrending(page, 'list');

    await page.getByRole('button', { name: 'Hide sidebar' }).click();

    await expect
      .poll(() =>
        page.evaluate(() => ({
          inStory: document.activeElement?.closest('app-story-layer') !== null,
          label: document.activeElement?.getAttribute('aria-label'),
        })),
      )
      .toEqual({ inStory: true, label: 'Show sidebar' });
    await page.getByRole('button', { name: 'Show sidebar' }).first().click();
  });
});
