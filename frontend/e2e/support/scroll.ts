import { expect, Page } from '@playwright/test';

/** The reader's list scrolls the document (#2038). */
export function pageScrollY(page: Page): Promise<number> {
  return page.evaluate(() => window.scrollY);
}

export async function scrollPageTo(page: Page, top: number): Promise<void> {
  await page.evaluate((to) => window.scrollTo({ top: to, behavior: 'instant' }), top);
  await expect.poll(() => pageScrollY(page)).toBe(top);
}
