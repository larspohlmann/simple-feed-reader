import { Injectable } from '@angular/core';

const KEY = 'sfr.audio.rate';

/** The listener's playback speed, device-local like the playlist (#1442). Its own key, because
 *  `stop()` clears the playlist's and the speed outlives a stop. */
@Injectable({ providedIn: 'root' })
export class PlaybackRateStore {
  load(): number | null {
    try {
      const rate = Number(localStorage.getItem(KEY));
      return Number.isFinite(rate) && rate > 0 ? rate : null;
    } catch {
      return null;
    }
  }

  save(rate: number): void {
    try {
      localStorage.setItem(KEY, String(rate));
    } catch {
      /* Storage blocked: the speed lasts for this page only. */
    }
  }
}
