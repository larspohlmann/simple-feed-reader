// src/app/reader/reading-layout.service.ts
import { Injectable, signal } from '@angular/core';

export type ReadingLayout = 'list' | 'pane';
const KEY = 'sfr.layout';

@Injectable({ providedIn: 'root' })
export class ReadingLayoutService {
  readonly mode = signal<ReadingLayout>(this.readSaved());

  set(mode: ReadingLayout): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
  }

  private readSaved(): ReadingLayout {
    return localStorage.getItem(KEY) === 'pane' ? 'pane' : 'list';
  }
}
