import { Injectable, signal } from '@angular/core';
import { clampListPercent, DEFAULT_LIST_PERCENT } from './pane-split';

const KEY = 'sfr.paneSplit';

@Injectable({ providedIn: 'root' })
export class PaneSplitService {
  readonly width = signal<number>(this.readSaved());

  set(percent: number): void {
    const value = clampListPercent(percent);
    localStorage.setItem(KEY, String(value));
    this.width.set(value);
  }

  reset(): void {
    this.set(DEFAULT_LIST_PERCENT);
  }

  private readSaved(): number {
    const saved = localStorage.getItem(KEY);
    // A blank string is "unset", not zero: `Number('')` is a finite 0 that would
    // otherwise clamp to the minimum rather than fall back to the default.
    if (saved === null || saved.trim() === '') {
      return DEFAULT_LIST_PERCENT;
    }
    return clampListPercent(Number(saved));
  }
}
