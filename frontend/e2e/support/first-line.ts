import { expect, Locator, Page } from '@playwright/test';
import { stubAccountProfile } from './auth';

export const TOLERANCE = 0.5;

/** An icon and the text it leads: the icon's mark, the element whose first line it hangs on, and
 *  the whole text block it sits in (the first line's element unless given). */
export interface LeadParts {
  icon: Locator;
  firstLine: Locator;
  block?: Locator;
}

export interface FirstLineMeasure {
  iconCentre: number;
  capMiddle: number;
  /** How many lines of the first line's height the text block spans. */
  lines: number;
}

export interface Rect {
  left: number;
  right: number;
  top: number;
  height: number;
}

export function expectNear(actual: number, expected: number, label: string) {
  expect(Math.abs(actual - expected), `${label}: ${actual} vs ${expected}`).toBeLessThanOrEqual(
    TOLERANCE,
  );
}

export function expectOnCapMiddle({ iconCentre, capMiddle }: FirstLineMeasure, label = 'icon') {
  expectNear(iconCentre, capMiddle, `${label} centre against the cap middle`);
}

/** The first line box of the first non-blank text in `holder`, outside the element `skip` selects. */
export async function firstTextRect(holder: Locator, skip: string | null = null): Promise<Rect> {
  return holder.evaluate((element, skipSelector) => {
    const skipped = skipSelector ? element.querySelector(skipSelector) : null;
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    while (node && (!node.textContent?.trim() || skipped?.contains(node))) node = walker.nextNode();
    const range = document.createRange();
    range.selectNodeContents(node!);
    const { left, right, top, height } = range.getClientRects()[0];
    return { left, right, top, height };
  }, skip);
}

/** A length `value` (a token, `1cap`) resolved in `element`'s font and context, in px. */
export async function measureToken(element: Locator, value: string): Promise<number> {
  return element.evaluate((host, length) => {
    const probe = document.createElement('div');
    probe.style.cssText = `position: absolute; visibility: hidden; width: ${length}`;
    host.append(probe);
    const width = probe.getBoundingClientRect().width;
    probe.remove();
    return width;
  }, value);
}

/** The element's height inside its padding and border, unrounded. */
export async function measureContentHeight(element: Locator): Promise<number> {
  return element.evaluate((host) => {
    const style = getComputedStyle(host);
    return (
      host.getBoundingClientRect().height -
      parseFloat(style.paddingTop) -
      parseFloat(style.paddingBottom) -
      parseFloat(style.borderTopWidth) -
      parseFloat(style.borderBottomWidth)
    );
  });
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
  block = firstLine,
}: LeadParts): Promise<FirstLineMeasure> {
  const iconHandle = await icon.elementHandle();
  const [iconCentre, line, blockHeight] = await Promise.all([
    icon.evaluate((element) => {
      const box = element.getBoundingClientRect();
      return box.top + box.height / 2;
    }),
    firstLine.evaluate(readFirstLine, iconHandle),
    measureContentHeight(block),
  ]);
  await iconHandle?.dispose();
  return {
    iconCentre,
    capMiddle: line.capMiddle,
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

/** `/api/me` answers a full profile whose account theme is `theme`. */
export async function stubAccountTheme(
  page: Page,
  theme: ThemeName,
  sidebarStyle: 'boxed' | 'airy' = 'boxed',
): Promise<void> {
  await stubAccountProfile(page, { theme, sidebarStyle });
}
