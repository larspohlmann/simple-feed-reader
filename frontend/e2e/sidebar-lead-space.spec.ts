import { expect, Page, test } from '@playwright/test';
import type { TagDto } from '../src/app/reader/models';
import type { SocialAccountRefDto } from '../src/app/reader/social-feed';
import { makeTag } from '../src/app/reader/testing/tag.factory';
import { aSubscription } from '../src/testing/subscription-testing';
import { presetLocalStorage } from './support/auth';
import {
  expectNear,
  expectTheme,
  measureContentHeight,
  measureToken,
  stubAccountTheme,
  ThemeName,
} from './support/first-line';
import { savedSearchWire, stubOneFeedReader, subscriptionsJson } from './support/reader';

/**
 * #2558: a sidebar row's left inset and its icon-to-name gap are both `(row height − 1cap) / 2`,
 * measured on that row; an indented run starts at its parent row's label. Every read is stubbed.
 */
type SidebarStyle = 'boxed' | 'airy';

const VARIANTS = (['graphite', 'classic'] as const).flatMap((theme) =>
  (['boxed', 'airy'] as const).map((style) => ({ theme, style })),
);

const NEWS = makeTag(1, 'News', { position: 0 });
const WORLD = makeTag(2, 'World', { parentId: 1, position: 0 });

const SUBSCRIPTIONS = [
  aSubscription({ id: 1, title: 'The Verge', unreadCount: 12, entryCount: 12, tags: [NEWS] }),
  aSubscription({ id: 2, title: 'Reuters', unreadCount: 4, entryCount: 4, tags: [WORLD] }),
  aSubscription({ id: 3, title: 'Daring Fireball', position: 1, unreadCount: 3, entryCount: 3 }),
  aSubscription({
    id: 4,
    title: 'Mastodon Home',
    unreadCount: 18,
    entryCount: 18,
    social: { network: 'mastodon', kind: 'home', accountId: 7 },
  }),
  aSubscription({
    id: 5,
    title: 'A profile',
    unreadCount: 2,
    entryCount: 2,
    social: { network: 'mastodon', kind: 'profile', accountId: null },
  }),
];

const SOCIAL_ACCOUNTS: SocialAccountRefDto[] = [
  {
    id: 7,
    network: 'mastodon',
    handle: '@reader@social.example',
    status: 'connected',
    serviceUrl: 'https://social.example',
  },
];

/** How the sidebar is opened: its theme and style, its tags, and the networks hidden from All items. */
interface SidebarSetup {
  theme: ThemeName;
  style: SidebarStyle;
  tags?: TagDto[];
  excludedNetworks?: string[];
}

async function openSidebar(
  page: Page,
  { theme, style, tags = [NEWS, WORLD], excludedNetworks = [] }: SidebarSetup,
): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed', [savedSearchWire({ id: 1, term: 'climate' })]);
  const subscriptions = subscriptionsJson(SUBSCRIPTIONS, { socialAccounts: SOCIAL_ACCOUNTS });
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: subscriptions }),
  );
  await page.route(/\/api\/tags(?:[?#]|$)/, (route) => route.fulfill({ json: { tags } }));
  await stubAccountTheme(page, theme, style);
  await presetLocalStorage(page, {
    'sfr.theme': 'light',
    'sfr.layout': 'magazine',
    'sfr.mastodon.profiles.collapsed': 'false',
  });
  await page.route('**/api/all-items/excluded-networks', (route) =>
    route.fulfill({ json: { excludedNetworks } }),
  );
  await page.goto('/');
  await expectTheme(page, theme);
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.getByRole('link', { name: /^All items/ })).toBeVisible();
  await sidebar.getByRole('button', { name: 'Toggle saved searches' }).click();
  await sidebar.getByRole('button', { name: 'Toggle News' }).click();
  await sidebar.getByRole('button', { name: 'Toggle World' }).click();
  await expect(sidebar.getByRole('link', { name: /Reuters/ })).toBeVisible();
  await expect(page.locator('[data-testid="sidebar-social-feeds"]')).toBeVisible();
  await page.evaluate(() => document.fonts.ready);
}

interface Lead {
  row: string;
  lead: string | null;
  label: string;
}

interface LeadMeasure {
  expected: number;
  start: number;
  gap: number;
  leadLeft: number;
  leadCentre: number;
  labelLeft: number;
}

/** `row` is the element whose height and font set the lead space; `lead` and `label` sit inside it. */
async function measure(page: Page, { row, lead, label }: Lead): Promise<LeadMeasure> {
  const rowElement = page.locator(row);
  const [height, cap, left, labelLeft, leadColumn] = await Promise.all([
    measureContentHeight(rowElement),
    measureToken(rowElement, '1cap'),
    // A head that bleeds to the card's edges starts, for its label, where it would without the bleed.
    rowElement.evaluate(
      (element) =>
        element.getBoundingClientRect().left -
        (parseFloat(getComputedStyle(element).marginLeft) || 0),
    ),
    page.locator(label).evaluate((element) => element.getBoundingClientRect().left),
    // A lead's column is its box padded by its start margin on both sides (a favicon's 2px).
    lead
      ? page.locator(lead).evaluate((element) => {
          const box = element.getBoundingClientRect();
          const margin = parseFloat(getComputedStyle(element).marginLeft) || 0;
          return { left: box.left - margin, right: box.right + margin };
        })
      : null,
  ]);
  const { left: leadLeft, right: leadRight } = leadColumn ?? { left: labelLeft, right: labelLeft };
  return {
    expected: (height - cap) / 2,
    start: leadLeft - left,
    gap: labelLeft - leadRight,
    leadLeft,
    leadCentre: (leadLeft + leadRight) / 2,
    labelLeft,
  };
}

const TAG_ROW = (id: number) => `.taghead[data-tag-id="${id}"] app-sidebar-group-row`;
const SOCIAL_KIND_ROW = '[data-testid="sidebar-social-feeds"] app-sidebar-group-row';
const feedRow = (scope: string): Lead => ({
  row: `${scope} app-sidebar-feed-row`,
  lead: `${scope} app-sidebar-feed-row .favicon-slot`,
  label: `${scope} app-sidebar-feed-row .name`,
});
const groupRow = (row: string): Lead => ({ row, lead: `${row} .lead`, label: `${row} .name` });

/** Rows on their own surface: the lead starts one lead space in, and its gap is one lead space. */
const OWN_SURFACE: Record<string, Lead> = {
  'a view head': {
    row: '.all-head',
    lead: '.all-head > .section-link > app-icon',
    label: '.all-head .label',
  },
  'the saved searches head': {
    row: '.savedsearch-head',
    lead: '.savedsearch-head > .section-link > app-icon',
    label: '.savedsearch-head .label',
  },
  'a saved search': {
    row: '.savedsearch-item',
    lead: '.savedsearch-item > app-icon',
    label: '.savedsearch-item .saved-term',
  },
  'a tag': groupRow(TAG_ROW(1)),
  'a child tag': groupRow(TAG_ROW(2)),
  'an untagged feed': feedRow('.feeds-card'),
  'a social account feed': feedRow('app-sidebar-social-section .sublist > .feedrow'),
  'a social feed kind': groupRow(SOCIAL_KIND_ROW),
};

/** Section heads: their label (or the social head's lead) follows the head's own formula; a head
 *  with a lead gaps it by the same lead space. */
const HEADS: Record<string, Lead> = {
  'the tags head': { row: '.tags-head', lead: null, label: '.tags-head .label' },
  'the feeds head': { row: '.feeds-head', lead: null, label: '.feeds-head .label' },
  'the social head': {
    row: '[data-testid="sidebar-social-mastodon"]',
    lead: '[data-testid="sidebar-social-mastodon"] .favicon-slot',
    label: '[data-testid="sidebar-social-mastodon"] .label',
  },
};

/** An indented run and the row whose label it starts at. */
const RUNS: Record<string, { child: Lead; parent: Lead }> = {
  'a tag’s feeds': { child: feedRow('.tag-group > .tagfeeds'), parent: groupRow(TAG_ROW(1)) },
  'a child tag’s feeds': {
    child: feedRow('.tag-child > .tagfeeds'),
    parent: groupRow(TAG_ROW(2)),
  },
  'a social feed kind’s feeds': {
    child: feedRow('app-sidebar-social-section .tagfeeds'),
    parent: groupRow(SOCIAL_KIND_ROW),
  },
  'a child tag’s glyph': { child: groupRow(TAG_ROW(2)), parent: groupRow(TAG_ROW(1)) },
};

/** The x an airy rail's hairline starts at. */
async function railLeft(page: Page, host: string, pseudo: '::before' | '::after'): Promise<number> {
  return page.evaluate(
    ([selector, element]) => {
      const rail = document.querySelector(selector!)!;
      return rail.getBoundingClientRect().left + parseFloat(getComputedStyle(rail, element).left);
    },
    [host, pseudo],
  );
}

/** The lead starts and gaps by one lead space; a lead-less head only starts by it. */
async function expectLeadSpaces(page: Page, name: string, lead: Lead): Promise<void> {
  const row = await measure(page, lead);
  expectNear(row.start, row.expected, `${name}: inset`);
  if (lead.lead) expectNear(row.gap, row.expected, `${name}: gap`);
}

const DESKTOP = { width: 1280, height: 900 };

test.use({ viewport: DESKTOP });

for (const { theme, style } of VARIANTS) {
  test.describe(`the ${style} sidebar, ${theme}`, () => {
    // The measurements only read: one sidebar serves them all.
    test.describe.configure({ mode: 'serial' });
    let page: Page;

    test.beforeAll(async ({ browser }) => {
      page = await browser.newPage({ baseURL: test.info().project.use.baseURL, viewport: DESKTOP });
      await openSidebar(page, { theme, style });
    });

    test.afterAll(async () => {
      await page.context().close();
    });

    test('every row on its own surface starts and gaps its lead by one lead space', async () => {
      for (const [name, lead] of Object.entries(OWN_SURFACE)) {
        await expectLeadSpaces(page, name, lead);
      }
    });

    test('a section head follows its own formula', async () => {
      for (const [name, lead] of Object.entries(HEADS)) {
        await expectLeadSpaces(page, name, lead);
      }
    });

    test('an indented run starts at its parent’s label and keeps its own gap', async () => {
      for (const [name, { child, parent }] of Object.entries(RUNS)) {
        const [childRow, parentRow] = [await measure(page, child), await measure(page, parent)];
        expectNear(childRow.leadLeft, parentRow.labelLeft, `${name}: lead against label`);
        expectNear(childRow.gap, childRow.expected, `${name}: gap`);
      }
    });

    test('the items after the name keep the row’s own gap', async () => {
      const gap = await page.evaluate(() => {
        const row = document.querySelector('.feeds-card app-sidebar-feed-row')!;
        const count = row.querySelector('.count')!.getBoundingClientRect();
        const menu = row.querySelector('app-sidebar-row-menu')!.getBoundingClientRect();
        return menu.left - count.right;
      });
      expectNear(gap, 12, 'count → ⋯ against --space-3');
    });

    if (style === 'airy') {
      test('a rail runs through its parent lead’s centre', async () => {
        const tag = await measure(page, groupRow(TAG_ROW(1)));
        const tagRail = await railLeft(page, '.tag-group.open', '::after');
        expectNear(tagRail, tag.leadCentre, 'tag rail');

        const head = await measure(page, HEADS['the social head']!);
        const socialRail = await railLeft(page, 'app-sidebar-social-section .sublist', '::before');
        expectNear(socialRail, head.leadCentre, 'social rail');
      });
    }
  });
}

const EXCLUDED_NEWS = makeTag(1, 'News', { position: 0, includeInAllItems: false });

/** A lead slot holding its glyph and the exclusion marker, per row. */
const MARKED_LEADS = {
  'a tag': { slot: `${TAG_ROW(1)} .favicon-slot`, glyph: 'app-tag-glyph' },
  'the network head': {
    slot: '[data-testid="sidebar-social-mastodon"] .favicon-slot',
    glyph: 'app-network-logo',
  },
};

for (const { theme, style } of VARIANTS) {
  test(`the ${style} ${theme} exclusion marker overhangs its glyph's bottom-right corner`, async ({
    page,
  }) => {
    await openSidebar(page, {
      theme,
      style,
      tags: [EXCLUDED_NEWS, WORLD],
      excludedNetworks: ['mastodon'],
    });
    for (const [name, { slot, glyph }] of Object.entries(MARKED_LEADS)) {
      const corner = await page.locator(slot).evaluate((element, glyphSelector) => {
        const glyphBox = element.querySelector(glyphSelector)!.getBoundingClientRect();
        const marker = element.querySelector('app-feed-exclusion-marker')!;
        const markerBox = marker.getBoundingClientRect();
        const markerStyle = getComputedStyle(marker);
        return {
          right: markerBox.right - glyphBox.right,
          bottom: markerBox.bottom - glyphBox.bottom,
          overhangRight: -parseFloat(markerStyle.right),
          overhangBottom: -parseFloat(markerStyle.bottom),
        };
      }, glyph);
      expectNear(corner.right, corner.overhangRight, `${name}: right`);
      expectNear(corner.bottom, corner.overhangBottom, `${name}: bottom`);
    }
  });
}

/** The widest network head: a four-digit count, the '+', the bell with a badge, ⋯ and the chevron. */
const BUSY_NETWORK = [
  aSubscription({
    id: 4,
    title: 'Mastodon Home',
    unreadCount: 2477,
    entryCount: 2477,
    social: { network: 'mastodon', kind: 'home', accountId: 7 },
  }),
];

async function openBusyNetwork(page: Page, theme: ThemeName, style: SidebarStyle): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed');
  const subscriptions = subscriptionsJson(BUSY_NETWORK, {
    socialAccounts: SOCIAL_ACCOUNTS,
    notifications: [{ network: 'mastodon', unseenCount: 3 }],
    unreadTotals: {
      ...subscriptionsJson(BUSY_NETWORK).unreadTotals,
      networks: [{ network: 'mastodon', unreadCount: 2477, profilesUnreadCount: 0 }],
    },
  });
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: subscriptions }),
  );
  await stubAccountTheme(page, theme, style);
  await presetLocalStorage(page, { 'sfr.theme': 'dark', 'sfr.layout': 'magazine' });
  await page.goto('/');
  await expectTheme(page, theme);
  await expect(page.getByTestId('sidebar-social-mastodon').locator('.section-count')).toHaveText(
    '2477',
  );
  await page.evaluate(() => document.fonts.ready);
}

for (const { theme, style } of VARIANTS) {
  test(`the ${style} ${theme} network head keeps its name whole beside a four-digit count`, async ({
    page,
  }) => {
    await openBusyNetwork(page, theme, style);
    const head = await page.getByTestId('sidebar-social-mastodon').evaluate((element) => {
      const box = (selector: string) => element.querySelector(selector)!.getBoundingClientRect();
      const label = element.querySelector<HTMLElement>('.label')!;
      return {
        room: label.clientWidth,
        needed: label.scrollWidth,
        composeToBell:
          box('app-sidebar-notifications-bell').left - box('app-sidebar-compose-button').right,
        bellToCount: box('.section-rest').left - box('app-sidebar-notifications-bell').right,
      };
    });
    expect(head.needed, `"Mastodon" needs ${head.needed}, has ${head.room}`).toBeLessThanOrEqual(
      head.room,
    );
    expectNear(head.composeToBell, -4, "'+' → bell");
    expectNear(head.bellToCount, 4, 'bell → count');
  });
}
