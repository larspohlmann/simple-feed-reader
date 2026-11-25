import type { Locator, Page } from '@playwright/test';
import { STUB_PROFILE } from './auth';

/** An icon and the text it leads: the icon's mark, the element whose first line it hangs on, and
 *  the whole text block it sits in. */
export interface LeadParts {
  icon: Locator;
  firstLine: Locator;
  block: Locator;
}

export interface FirstLineMeasure {
  iconCentre: number;
  lineCentre: number;
  /** Icon centre minus line-box centre, in px. */
  offset: number;
  /** How many lines of the first line's height the text block spans. */
  lines: number;
}

/** The first line's centre is its line box's: the element's content top plus half its computed
 *  line height. A `Range` rect is the font's content area and sits ~0.75px off it. */
export async function measureFirstLine({
  icon,
  firstLine,
  block,
}: LeadParts): Promise<FirstLineMeasure> {
  const [iconBox, line, blockHeight] = await Promise.all([
    icon.evaluate((element) => {
      const box = element.getBoundingClientRect();
      return box.top + box.height / 2;
    }),
    firstLine.evaluate((element) => {
      const style = getComputedStyle(element);
      const lineHeight = parseFloat(style.lineHeight);
      const top =
        element.getBoundingClientRect().top +
        parseFloat(style.borderTopWidth) +
        parseFloat(style.paddingTop);
      return { centre: top + lineHeight / 2, lineHeight };
    }),
    block.evaluate((element) => {
      const style = getComputedStyle(element);
      return (
        element.getBoundingClientRect().height -
        parseFloat(style.paddingTop) -
        parseFloat(style.paddingBottom) -
        parseFloat(style.borderTopWidth) -
        parseFloat(style.borderBottomWidth)
      );
    }),
  ]);
  return {
    iconCentre: iconBox,
    lineCentre: line.centre,
    offset: iconBox - line.centre,
    lines: blockHeight / line.lineHeight,
  };
}

export type ThemeName = 'graphite' | 'classic';

const PREFERENCES = {
  scrapeFallbackEnabled: false,
  digest: { enabled: false, cadence: 'daily', sendHour: 8, weekday: 1, timezone: 'UTC' },
  passkeyOfferAnswered: true,
  magazineStyle: 'boxed',
  sidebarStyle: 'boxed',
  handedness: 'right',
  trendingEnabled: true,
};

/** `/api/me` answers a full profile whose account theme is `theme`. */
export async function stubAccountTheme(page: Page, theme: ThemeName): Promise<void> {
  await page.route(
    (url) => url.pathname === '/api/me',
    (route) =>
      route.request().method() !== 'GET'
        ? route.fallback()
        : route.fulfill({
            json: {
              ...STUB_PROFILE,
              email: 'lead@example.invalid',
              roles: ['ROLE_USER'],
              status: 'active',
              createdAt: '2026-07-01T00:00:00+00:00',
              locale: 'en',
              trialEndsAt: null,
              ai: { ready: false, model: null, capabilities: null },
              mail: { enabled: false },
              emailVerified: true,
              preferences: { ...PREFERENCES, theme },
            },
          }),
  );
}
