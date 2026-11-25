import { expect, Locator, Page } from '@playwright/test';
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
  capMiddle: number;
  /** Icon centre minus the first line's cap middle, in px. */
  offset: number;
  /** How many lines of the first line's height the text block spans. */
  lines: number;
}

/** Runs in the page: the first line's cap middle and line height, read before its first character. */
function readFirstLine(element: Element, iconElement: Element | null) {
  const findFirstText = (): Text => {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      if (node.textContent?.trim() && !iconElement?.contains(node)) return node as Text;
    }
    throw new Error('no text in the first line');
  };
  const measureCapBefore = (text: Text, parent: HTMLElement): DOMRect => {
    const itemised = /flex|grid/.test(getComputedStyle(parent).display);
    const host = itemised ? document.createElement('span') : parent;
    if (itemised) {
      parent.insertBefore(host, text);
      host.append(text);
    }
    const probe = document.createElement('span');
    probe.style.cssText = 'display: inline-block; width: 0; height: 1cap; vertical-align: baseline';
    host.insertBefore(probe, text);
    const box = probe.getBoundingClientRect();
    probe.remove();
    if (itemised) {
      parent.insertBefore(text, host);
      host.remove();
    }
    return box;
  };
  const text = findFirstText();
  const parent = text.parentElement!;
  const cap = measureCapBefore(text, parent);
  return {
    capMiddle: cap.top + cap.height / 2,
    lineHeight: parseFloat(getComputedStyle(parent).lineHeight),
  };
}

/** The first line's cap middle: a `1cap` probe set on the baseline just before its first character.
 *  Text that is a flex or grid item of its own is wrapped while it is measured, so the probe shares
 *  its line instead of becoming an item beside it. */
export async function measureFirstLine({
  icon,
  firstLine,
  block,
}: LeadParts): Promise<FirstLineMeasure> {
  const iconHandle = await icon.elementHandle();
  const [iconCentre, line, blockHeight] = await Promise.all([
    icon.evaluate((element) => {
      const box = element.getBoundingClientRect();
      return box.top + box.height / 2;
    }),
    firstLine.evaluate(readFirstLine, iconHandle),
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
  await iconHandle?.dispose();
  return {
    iconCentre,
    capMiddle: line.capMiddle,
    offset: iconCentre - line.capMiddle,
    lines: blockHeight / line.lineHeight,
  };
}

export type ThemeName = 'graphite' | 'classic';

/** Waits until the page wears `theme`: graphite carries no theme-name attribute. */
export async function expectTheme(page: Page, theme: ThemeName): Promise<void> {
  const html = page.locator('html');
  if (theme === 'classic') await expect(html).toHaveAttribute('data-theme-name', 'classic');
  else await expect(html).not.toHaveAttribute('data-theme-name', /.*/);
}

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
export async function stubAccountTheme(
  page: Page,
  theme: ThemeName,
  sidebarStyle: 'boxed' | 'airy' = 'boxed',
): Promise<void> {
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
              preferences: { ...PREFERENCES, theme, sidebarStyle },
            },
          }),
  );
}
