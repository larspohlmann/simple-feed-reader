import { Injectable, signal } from '@angular/core';

const KEY = 'sfr.audio.rate';
/** The speeds the speed control steps through, in order (#1442). */
export const PLAYBACK_RATES: readonly number[] = [1, 1.25, 1.5, 2];

/** The listener's playback speed, device-local like the playlist (#1442). Its own key, because
 *  the playlist's is cleared by `stop()` and the speed outlives a stop. */
@Injectable({ providedIn: 'root' })
export class PlaybackRate {
  private readonly _value = signal(this.load());
  readonly value = this._value.asReadonly();

  cycle(): number {
    const next =
      PLAYBACK_RATES[(PLAYBACK_RATES.indexOf(this._value()) + 1) % PLAYBACK_RATES.length];
    this._value.set(next);
    this.save(next);
    return next;
  }

  private load(): number {
    try {
      const saved = Number(localStorage.getItem(KEY));
      return PLAYBACK_RATES.includes(saved) ? saved : 1;
    } catch {
      return 1;
    }
  }

  private save(rate: number): void {
    try {
      localStorage.setItem(KEY, String(rate));
    } catch {
      /* Storage blocked: the speed lasts for this page only. */
    }
  }
}
