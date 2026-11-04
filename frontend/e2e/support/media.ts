import { Page } from '@playwright/test';

export interface Play {
  src: string;
  muted: boolean;
}

/** Chromium here has no H.264, so `play()` is recorded instead of decoding a clip. A video asked
 *  to play before its source arrives (#1903) plays for real: that only marks it as wanting to. */
export async function recordPlays(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const plays: Play[] = [];
    (window as unknown as { plays: Play[] }).plays = plays;
    const play = HTMLMediaElement.prototype.play;
    HTMLMediaElement.prototype.play = function (this: HTMLMediaElement) {
      if (!this.src) return play.call(this);
      plays.push({ src: this.src, muted: this.muted });
      return Promise.resolve();
    };
  });
}

export const plays = (page: Page): Promise<Play[]> =>
  page.evaluate(() => (window as unknown as { plays: Play[] }).plays);
