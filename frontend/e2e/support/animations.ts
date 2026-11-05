import type { Locator, Page } from '@playwright/test';

/** Waits for the element's finite animations (and its descendants') to finish. */
export function settleAnimations(locator: Locator): Promise<unknown> {
  return locator.evaluate((element) =>
    Promise.all(
      element
        .getAnimations({ subtree: true })
        .filter((animation) => animation.effect?.getComputedTiming().endTime !== Infinity)
        .map((animation) => animation.finished.catch(() => undefined)),
    ),
  );
}

/** Two frames, so a scroll event has been heard and any restore re-assert has settled. */
export async function nextFrames(page: Page): Promise<void> {
  await page.evaluate(
    () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
  );
}
