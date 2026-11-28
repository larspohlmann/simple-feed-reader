import { expect, Page } from '@playwright/test';
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
  ONE_ENTRY,
  openArticle,
  ORGANISE,
  SCORED_ARTICLE,
  stubMagazine,
  TAGS,
} from './support/icon-rows';
import { MENU_FINE, openAccountMenu } from './support/menu-rows';

/**
 * #2563: every slot the icon-lead module styles keeps its cap strut, whatever a consumer's own rules
 * say. Slots are found by their strut rule, so a surface needs no list of its rows.
 */
const SETUP: Setup = { theme: 'graphite', step: 100 };

interface SlotSurface extends Surface {
  name: string;
  /** Brings the surface's rows on screen. */
  open: (page: Page) => Promise<void>;
}

const MAGAZINE: Surface = {
  viewport: DESKTOP,
  stub: (page) => stubMagazine(page, ONE_ENTRY, { tags: TAGS }),
  path: '/?subscription=1',
};

const SURFACES: SlotSurface[] = [
  { name: 'the article', ...SCORED_ARTICLE, open: openArticle },
  {
    name: 'the list',
    ...MAGAZINE,
    open: (page) => expect(page.locator('app-entry-list').getByText('One')).toBeVisible(),
  },
  {
    name: 'organise',
    ...ORGANISE,
    open: (page) => expect(page.getByRole('button', { name: 'New tag' })).toBeVisible(),
  },
  {
    name: 'the account menu',
    ...MENU_FINE,
    open: async (page) => {
      await openAccountMenu(page);
    },
  },
  {
    name: 'the tag picker',
    ...MAGAZINE,
    open: async (page) => {
      await page.getByRole('button', { name: 'Edit feed' }).first().click();
      await expect(page.locator('app-tag-chips .chip').first()).toBeVisible();
    },
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

      const slots = await findLeadSlots(page);

      expect(slots.length, 'slots found').toBeGreaterThan(0);
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
