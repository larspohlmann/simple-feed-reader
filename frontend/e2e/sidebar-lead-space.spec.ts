import { expect, Page, test } from '@playwright/test';
import type { SocialAccountRefDto } from '../src/app/reader/social-feed';
import { makeTag } from '../src/app/reader/testing/tag.factory';
import { aSubscription } from '../src/testing/subscription-testing';
import { presetLocalStorage } from './support/auth';
import { stubAccountTheme, ThemeName } from './support/first-line';
import {
  savedSearchesJson,
  savedSearchWire,
  stubOneFeedReader,
  subscriptionsJson,
} from './support/reader';

/**
 * #2558: a sidebar row's left inset and its icon-to-name gap are both `(row height − 1cap) / 2`,
 * measured on that row; an indented run starts at its parent row's label. Every read is stubbed.
 */
const TOLERANCE = 0.5;

type SidebarStyle = 'boxed' | 'airy';

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

async function openSidebar(page: Page, theme: ThemeName, style: SidebarStyle): Promise<void> {
  await stubOneFeedReader(page, 'Fixture feed', [savedSearchWire({ id: 1, term: 'climate' })]);
  const subscriptions = subscriptionsJson(SUBSCRIPTIONS, { socialAccounts: SOCIAL_ACCOUNTS });
  await page.route(/\/api\/subscriptions(?:[?#]|$)/, (route) =>
    route.fulfill({ json: subscriptions }),
  );
  await page.route(/\/api\/tags(?:[?#]|$)/, (route) =>
    route.fulfill({ json: { tags: [NEWS, WORLD] } }),
  );
  await page.route(/\/api\/saved-searches(?:[?#]|$)/, (route) =>
    route.fulfill({ json: savedSearchesJson(savedSearchWire({ id: 1, term: 'climate' })) }),
  );
  await stubAccountTheme(page, theme, style);
  await presetLocalStorage(page, {
    'sfr.theme': 'light',
    'sfr.layout': 'magazine',
    'sfr.mastodon.profiles.collapsed': 'false',
  });
  await page.goto('/');
  const sidebar = page.getByRole('navigation', { name: 'Feeds' });
  await expect(sidebar.getByRole('link', { name: /All items/ })).toBeVisible();
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
  return page.evaluate(
    ([rowSelector, leadSelector, labelSelector]) => {
      const find = (selector: string): HTMLElement => {
        const element = document.querySelector<HTMLElement>(selector);
        if (!element) throw new Error(`no ${selector}`);
        return element;
      };
      const rowElement = find(rowSelector!);
      const rowStyle = getComputedStyle(rowElement);
      const rowBox = rowElement.getBoundingClientRect();
      const probe = document.createElement('span');
      probe.style.cssText = 'position: absolute; width: 1cap; visibility: hidden';
      rowElement.append(probe);
      const cap = parseFloat(getComputedStyle(probe).width);
      probe.remove();
      const px = (value: string) => parseFloat(value) || 0;
      const height =
        rowBox.height -
        px(rowStyle.paddingTop) -
        px(rowStyle.paddingBottom) -
        px(rowStyle.borderTopWidth) -
        px(rowStyle.borderBottomWidth);
      // A head that bleeds to the card's edges starts, for its label, where it would without the bleed.
      const left = rowBox.left - px(rowStyle.marginLeft);
      const labelLeft = find(labelSelector!).getBoundingClientRect().left;
      let leadLeft = labelLeft;
      let leadRight = labelLeft;
      if (leadSelector) {
        const leadElement = find(leadSelector);
        const leadStyle = getComputedStyle(leadElement);
        const leadBox = leadElement.getBoundingClientRect();
        leadLeft = leadBox.left - px(leadStyle.marginLeft);
        leadRight = leadBox.right + px(leadStyle.marginRight);
      }
      return {
        expected: (height - cap) / 2,
        start: leadLeft - left,
        gap: labelLeft - leadRight,
        leadLeft,
        leadCentre: (leadLeft + leadRight) / 2,
        labelLeft,
      };
    },
    [row, lead, label],
  );
}

const TAG_ROW = (id: number) => `.taghead[data-tag-id="${id}"] app-sidebar-group-row`;
const SOCIAL_KIND_ROW = '[data-testid="sidebar-social-feeds"] app-sidebar-group-row';
const feedRow = (scope: string): Lead => ({
  row: `${scope} app-sidebar-feed-row`,
  lead: `${scope} app-sidebar-feed-row .favicon-slot`,
  label: `${scope} app-sidebar-feed-row .name`,
});
const groupRow = (row: string): Lead => ({ row, lead: `${row} .lead`, label: `${row} .name` });

/** Rows on their own surface: the lead starts one lead space in. */
const OWN_SURFACE: Record<string, Lead> = {
  'a view': {
    row: 'app-sidebar-views .nav.all',
    lead: 'app-sidebar-views .nav.all > app-icon',
    label: 'app-sidebar-views .nav.all .name',
  },
  'a saved search': {
    row: '.savedsearch-item',
    lead: '.savedsearch-item > app-icon',
    label: '.savedsearch-item .saved-term',
  },
  'a tag': groupRow(TAG_ROW(1)),
  'a child tag': groupRow(TAG_ROW(2)),
  'an untagged feed': feedRow('.feeds-card'),
  'a social account feed': feedRow('app-sidebar-social-section .rows > .feedrow'),
  'a social feed kind': groupRow(SOCIAL_KIND_ROW),
};

/** Section heads: their label (or the social head's lead) follows the head's own formula. */
const HEADS: Record<string, Lead> = {
  'the saved searches head': {
    row: '.savedsearch-head',
    lead: null,
    label: '.savedsearch-head .label',
  },
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
};

/** The x an airy rail's hairline starts at. */
async function railLeft(page: Page, host: string, pseudo: '::before' | '::after'): Promise<number> {
  return page.evaluate(
    ([selector, element]) => {
      const box = document.querySelector(selector!)!.getBoundingClientRect();
      return (
        box.left + parseFloat(getComputedStyle(document.querySelector(selector!)!, element).left)
      );
    },
    [host, pseudo],
  );
}

test.use({ viewport: { width: 1280, height: 900 } });

for (const theme of ['graphite', 'classic'] as const) {
  for (const style of ['boxed', 'airy'] as const) {
    test.describe(`the ${style} sidebar, ${theme}`, () => {
      test.beforeEach(async ({ page }) => openSidebar(page, theme, style));

      test('every row on its own surface starts and gaps its lead by one lead space', async ({
        page,
      }) => {
        for (const [name, lead] of Object.entries(OWN_SURFACE)) {
          const row = await measure(page, lead);
          expect(
            Math.abs(row.start - row.expected),
            `${name}: inset ${row.start} vs ${row.expected}`,
          ).toBeLessThanOrEqual(TOLERANCE);
          expect(
            Math.abs(row.gap - row.expected),
            `${name}: gap ${row.gap} vs ${row.expected}`,
          ).toBeLessThanOrEqual(TOLERANCE);
        }
      });

      test('a section head follows its own formula', async ({ page }) => {
        for (const [name, lead] of Object.entries(HEADS)) {
          const head = await measure(page, lead);
          expect(
            Math.abs(head.start - head.expected),
            `${name}: inset ${head.start} vs ${head.expected}`,
          ).toBeLessThanOrEqual(TOLERANCE);
          if (lead.lead) {
            expect(
              Math.abs(head.gap - head.expected),
              `${name}: gap ${head.gap} vs ${head.expected}`,
            ).toBeLessThanOrEqual(TOLERANCE);
          }
        }
      });

      test('an indented run starts at its parent’s label and keeps its own gap', async ({
        page,
      }) => {
        for (const [name, { child, parent }] of Object.entries(RUNS)) {
          const [childRow, parentRow] = [await measure(page, child), await measure(page, parent)];
          expect(
            Math.abs(childRow.leadLeft - parentRow.labelLeft),
            `${name}: lead ${childRow.leadLeft} vs label ${parentRow.labelLeft}`,
          ).toBeLessThanOrEqual(TOLERANCE);
          expect(
            Math.abs(childRow.gap - childRow.expected),
            `${name}: gap ${childRow.gap} vs ${childRow.expected}`,
          ).toBeLessThanOrEqual(TOLERANCE);
        }
      });

      test('a child tag’s glyph starts at its parent tag’s label', async ({ page }) => {
        const [child, parent] = [
          await measure(page, groupRow(TAG_ROW(2))),
          await measure(page, groupRow(TAG_ROW(1))),
        ];
        expect(
          Math.abs(child.leadLeft - parent.labelLeft),
          `glyph ${child.leadLeft} vs label ${parent.labelLeft}`,
        ).toBeLessThanOrEqual(TOLERANCE);
      });

      if (style === 'airy') {
        test('a rail runs through its parent lead’s centre', async ({ page }) => {
          const tag = await measure(page, groupRow(TAG_ROW(1)));
          const tagRail = await railLeft(page, '.tag-group.open', '::after');
          expect(
            Math.abs(tagRail - tag.leadCentre),
            `tag rail ${tagRail} vs ${tag.leadCentre}`,
          ).toBeLessThanOrEqual(TOLERANCE);

          const head = await measure(page, HEADS['the social head']!);
          const socialRail = await railLeft(page, 'app-sidebar-social-section .rows', '::before');
          expect(
            Math.abs(socialRail - head.leadCentre),
            `social rail ${socialRail} vs ${head.leadCentre}`,
          ).toBeLessThanOrEqual(TOLERANCE);
        });
      }
    });
  }
}
