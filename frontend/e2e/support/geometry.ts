import type { Page } from '@playwright/test';

/** The y of the first match's bottom rule: its box's bottom less its bottom border. */
export async function bottomBorderEdge(page: Page, selector: string): Promise<number> {
  return page
    .locator(selector)
    .first()
    .evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return rect.bottom - Number.parseFloat(getComputedStyle(element).borderBottomWidth);
    });
}
