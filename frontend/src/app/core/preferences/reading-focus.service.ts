import { Injectable, signal } from '@angular/core';

const KEY = 'sfr.readingFocus';

@Injectable({ providedIn: 'root' })
export class ReadingFocusService {
  readonly enabled = signal(localStorage.getItem(KEY) !== 'false');

  setEnabled(enabled: boolean): void {
    localStorage.setItem(KEY, String(enabled));
    this.enabled.set(enabled);
  }
}
