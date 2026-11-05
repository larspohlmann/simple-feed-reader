import { expect, Page } from '@playwright/test';

/** The reader's list scrolls the document (#2038). */
export function pageScrollY(page: Page): Promise<number> {
  return page.evaluate(() => window.scrollY);
}

export async function scrollPageTo(page: Page, top: number): Promise<void> {
  await page.evaluate((to) => window.scrollTo({ top: to, behavior: 'instant' }), top);
  await expect.poll(() => pageScrollY(page)).toBe(top);
}

/** Scrolls the page to its last offset and returns it. */
export async function scrollPageToEnd(page: Page): Promise<number> {
  const end = await page.evaluate(() => document.documentElement.scrollHeight - window.innerHeight);
  await scrollPageTo(page, end);
  return end;
}

/** The title of a row in the middle third, so clicking it scrolls nothing into view. */
export function rowTitleInView(page: Page): Promise<string> {
  return page.evaluate(() => {
    const titles = Array.from(document.querySelectorAll<HTMLElement>('app-entry-row .title'));
    const third = window.innerHeight / 3;
    const visible = titles.find((title) => {
      const box = title.getBoundingClientRect();
      return box.top > third && box.bottom < 2 * third;
    });
    return visible?.textContent?.trim() ?? '';
  });
}

export interface TouchPoint {
  readonly x: number;
  readonly y: number;
}

/** A one-finger drag on the first match of `selector`, from `from` through `to`, not yet lifted. */
export async function touchDrag(
  page: Page,
  selector: string,
  from: TouchPoint,
  to: TouchPoint,
): Promise<void> {
  await page
    .locator(selector)
    .first()
    .evaluate(
      (target, points) => {
        const fire = (type: string, point: { x: number; y: number }): void => {
          const touch = new Touch({ identifier: 1, target, clientX: point.x, clientY: point.y });
          target.dispatchEvent(
            new TouchEvent(type, { bubbles: true, cancelable: true, touches: [touch] }),
          );
        };
        const midway = {
          x: (points.from.x + points.to.x) / 2,
          y: (points.from.y + points.to.y) / 2,
        };
        fire('touchstart', points.from);
        fire('touchmove', midway);
        fire('touchmove', points.to);
      },
      { from, to },
    );
}

export async function liftTouch(page: Page, selector: string): Promise<void> {
  await page
    .locator(selector)
    .first()
    .evaluate((target) =>
      target.dispatchEvent(new TouchEvent('touchend', { bubbles: true, touches: [] })),
    );
}
