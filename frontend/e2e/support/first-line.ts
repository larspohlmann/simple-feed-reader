import { BrowserContext, expect, Locator, Page, test as base } from '@playwright/test';
import { settleAnimations } from './animations';
import { presetLocalStorage, stubAccountProfile } from './auth';

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

export const PHONE = { width: 375, height: 812 };
export const DESKTOP = { width: 1280, height: 900 };

export interface Setup {
  theme: ThemeName;
  step: 100 | 150;
}

export interface Viewport {
  width: number;
  height: number;
}

/** Where a case opens: its viewport and pointer, its local storage, its API stubs and its path. */
export interface Surface {
  viewport?: Viewport;
  touch?: boolean;
  storage?: Record<string, string>;
  stub: (page: Page) => Promise<void>;
  path: string;
}

/** `openSurface` opens a page on `surface` in `setup`; every context it opened closes after the test. */
export const test = base.extend<{ openSurface: (setup: Setup, surface: Surface) => Promise<Page> }>(
  {
    openSurface: async ({ browser }, use) => {
      const contexts: BrowserContext[] = [];
      await use(
        async ({ step, theme }, { viewport = PHONE, touch = false, storage, stub, path }) => {
          const context = await browser.newContext({
            baseURL: test.info().project.use.baseURL,
            viewport,
            hasTouch: touch,
            isMobile: touch,
          });
          contexts.push(context);
          const page = await context.newPage();
          await page.clock.setFixedTime(new Date('2026-11-24T12:00:00Z'));
          await presetLocalStorage(page, {
            'sfr.textSize': String(step),
            'sfr.layout': 'pane',
            'sfr.theme': 'light',
            ...storage,
          });
          await stub(page);
          // After the surface stubs: a later route wins, and they answer /api/me too.
          await stubAccountTheme(page, theme);
          await page.goto(path);
          return page;
        },
      );
      await Promise.all(contexts.map((context) => context.close()));
    },
  },
);

export async function settle(page: Page, theme: ThemeName): Promise<void> {
  await expectTheme(page, theme);
  await page.evaluate(() => document.fonts.ready);
  await settleAnimations(page.locator('body'));
}

/** An element an icon-lead strut rule selects, as it renders. `alone`: its strut is dropped. */
export interface SlotReport {
  selector: string;
  alone: boolean;
  strutBlockSize: number;
  capHeight: number;
  display: string;
  alignSelf: string;
  /** The row centres its items on a named text, so the slot must take the baseline itself. */
  hasText: boolean;
}

/** Runs in the page: the slot selector of every `::before { block-size: var(--icon-lead-cap) }` rule, in
 *  every sheet and every grouping rule whose media and supports conditions hold. */
function collectSlotSelectors(): string[] {
  const STRUT = '::before';
  const splitSelectorList = (list: string): string[] => {
    const selectors: string[] = [];
    let depth = 0;
    let start = 0;
    [...list].forEach((character, index) => {
      if ('(['.includes(character)) depth++;
      else if (')]'.includes(character)) depth--;
      else if (character === ',' && depth === 0) {
        selectors.push(list.slice(start, index).trim());
        start = index + 1;
      }
    });
    selectors.push(list.slice(start).trim());
    return selectors;
  };
  const holds = (rule: CSSRule): boolean => {
    if (rule instanceof CSSMediaRule) return matchMedia(rule.media.mediaText).matches;
    if (rule instanceof CSSSupportsRule) return CSS.supports(rule.conditionText);
    return true;
  };
  const slotSelectors = new Set<string>();
  const collect = (rules: CSSRuleList): void => {
    for (const rule of rules) {
      if (rule instanceof CSSStyleRule) {
        if (rule.style.getPropertyValue('block-size').trim() !== 'var(--icon-lead-cap)') continue;
        splitSelectorList(rule.selectorText)
          .filter((selector) => selector.endsWith(STRUT))
          .forEach((selector) => slotSelectors.add(selector.slice(0, -STRUT.length)));
      } else if (rule instanceof CSSGroupingRule && holds(rule)) {
        collect(rule.cssRules);
      }
    }
  };
  for (const sheet of [...document.styleSheets, ...document.adoptedStyleSheets]) {
    try {
      collect(sheet.cssRules);
    } catch {
      // A cross-origin sheet hides its rules, and no strut lives in one.
    }
  }
  return [...slotSelectors];
}

/** Runs in the page: each rendered element the selectors pick, once, with its strut and the cap. */
function readSlots(selectors: string[]): SlotReport[] {
  const measureCap = (slot: Element): number => {
    const probe = document.createElement('div');
    probe.style.cssText = 'position: absolute; visibility: hidden; width: var(--icon-lead-cap)';
    // In the row: a slot may be scaled, which would scale the probe with it.
    (slot.parentElement ?? slot).append(probe);
    const width = probe.getBoundingClientRect().width;
    probe.remove();
    return width;
  };
  const reported = new Set<Element>();
  const reports: SlotReport[] = [];
  for (const selector of selectors) {
    for (const slot of document.querySelectorAll(selector)) {
      if (reported.has(slot) || slot.getClientRects().length === 0) continue;
      reported.add(slot);
      const style = getComputedStyle(slot);
      const strut = getComputedStyle(slot, '::before');
      const row = slot.parentElement;
      reports.push({
        selector,
        alone: strut.content === 'none',
        strutBlockSize: parseFloat(strut.blockSize) || 0,
        capHeight: measureCap(slot),
        display: style.display,
        alignSelf: style.alignSelf,
        hasText: !!row && getComputedStyle(row).alignItems !== 'baseline',
      });
    }
  }
  return reports;
}

/** Every icon-lead slot on the page, found by its strut rule rather than listed. */
export async function findLeadSlots(page: Page): Promise<SlotReport[]> {
  return page.evaluate(readSlots, await page.evaluate(collectSlotSelectors));
}
