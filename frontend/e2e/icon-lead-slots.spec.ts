import { expect, Page } from '@playwright/test';
import type { EntryDto } from '../src/app/reader/models';
import {
  DESKTOP,
  expectNear,
  findLeadSlots,
  PHONE,
  settle,
  Setup,
  Surface,
  test,
} from './support/first-line';
import {
  COUNTED_ENTRY,
  MAGAZINE,
  openArticle,
  openTagPicker,
  ORGANISE,
  SCORED_ARTICLE,
  stubMagazine,
  stubSuggestedTags,
  UNLIKABLE_ENTRY,
} from './support/icon-rows';
import { MENU_FINE, openAccountMenu } from './support/menu-rows';

/**
 * #2563: every slot the icon-lead module styles keeps its cap strut, whatever a consumer's own rules
 * say. Slots are found by their strut rule, so a surface needs no list of its rows.
 */
const SETUP: Setup = { theme: 'graphite', step: 100 };

interface SlotSurface extends Surface {
  name: string;
  open: (page: Page) => Promise<void>;
  /** Slots the surface must show, so it cannot quietly lose its rows. */
  expected: string[];
}

const LIKED_AND_UNLIKABLE: EntryDto[] = [
  COUNTED_ENTRY,
  { ...UNLIKABLE_ENTRY, id: 2, title: 'Two' },
];

const SURFACES: SlotSurface[] = [
  {
    name: 'the article',
    ...SCORED_ARTICLE,
    open: openArticle,
    expected: ['.byline > app-avatar', 'app-social-counts-row .count > app-icon'],
  },
  {
    name: 'the list',
    ...MAGAZINE,
    stub: (page) => stubMagazine(page, LIKED_AND_UNLIKABLE),
    open: async (page) => {
      await expect(page.locator('app-entry-like .likes')).toBeVisible();
      await expect(page.locator('app-entry-like button')).toBeVisible();
    },
    expected: [
      'app-entry-like button > app-icon',
      'app-entry-like .likes > app-icon',
      'app-entry-meta .comments > app-icon',
    ],
  },
  {
    name: 'organise',
    ...ORGANISE,
    open: (page) => expect(page.getByRole('button', { name: 'New tag' })).toBeVisible(),
    expected: ['button.sm > app-icon'],
  },
  {
    name: 'the account menu',
    ...MENU_FINE,
    open: async (page) => {
      await openAccountMenu(page);
    },
    expected: [],
  },
  {
    name: 'the tag picker',
    ...MAGAZINE,
    stub: stubSuggestedTags,
    open: async (page) => {
      await openTagPicker(page);
      await expect(page.locator('app-tag-picker .suggestions button').first()).toBeVisible();
    },
    expected: ['.suggestion > app-tag-glyph'],
  },
];

const POINTERS = [
  { pointer: 'fine', viewport: DESKTOP, touch: false },
  { pointer: 'coarse', viewport: PHONE, touch: true },
];

for (const surface of SURFACES) {
  for (const { pointer, viewport, touch } of POINTERS) {
    test(`every icon-lead slot on ${surface.name}, ${pointer}, keeps its cap strut`, async ({
      openSurface,
    }) => {
      const page = await openSurface(SETUP, { ...surface, viewport, touch });
      await surface.open(page);
      await settle(page, SETUP.theme);

      const slots = await findLeadSlots(page, surface.expected);

      expect(slots.length, 'slots found').toBeGreaterThan(0);
      for (const selector of surface.expected) {
        expect(
          slots.some((slot) => slot.expected.includes(selector)),
          `${selector} reported`,
        ).toBe(true);
      }
      for (const slot of slots) {
        if (slot.alone) {
          expect(slot.alignSelf, `${slot.selector} alone`).toBe('center');
          continue;
        }
        expect(slot.capHeight, `${slot.selector} cap`).toBeGreaterThan(0);
        expectNear(slot.strutBlockSize, slot.capHeight, `${slot.selector} strut against the cap`);
        expect(slot.display, `${slot.selector} display`).toBe('flex');
        if (slot.hasText) expect(slot.alignSelf, `${slot.selector} align-self`).toBe('baseline');
      }
    });
  }
}
