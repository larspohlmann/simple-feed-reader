// src/app/reader/reading-layout.service.ts
import { Injectable, signal } from '@angular/core';

export type ReadingLayout = 'list' | 'pane' | 'magazine';
const KEY = 'sfr.layout';
const MODES: ReadingLayout[] = ['list', 'pane', 'magazine'];

@Injectable({ providedIn: 'root' })
export class ReadingLayoutService {
  readonly mode = signal<ReadingLayout>(this.readSaved());

  set(mode: ReadingLayout): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
  }

  private readSaved(): ReadingLayout {
    const saved = localStorage.getItem(KEY) as ReadingLayout | null;
    return saved && MODES.includes(saved) ? saved : 'magazine';
  }
}
