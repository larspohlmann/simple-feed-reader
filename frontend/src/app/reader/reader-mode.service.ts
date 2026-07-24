import { Injectable, signal } from '@angular/core';

export type ReaderMode = 'reader' | 'original';

/**
 * Shared reader/original toggle state for the open article. Lifting it out of
 * ReaderViewComponent lets the top bar drive the switch while the article view
 * still owns the loading lifecycle that decides whether toggling is possible.
 * Only one article is ever open at a time, so a single shared instance is safe.
 */
@Injectable({ providedIn: 'root' })
export class ReaderModeService {
  readonly mode = signal<ReaderMode>('reader');
  /** True once a reader-extracted article exists to switch to/from. */
  readonly canToggle = signal(false);

  /** A new article is loading: default to reader view, switching disabled. */
  reset(): void {
    this.mode.set('reader');
    this.canToggle.set(false);
  }

  /** Extraction succeeded: allow switching between reader and original. */
  enableToggle(): void {
    this.canToggle.set(true);
  }

  /** Extraction failed: only the feed's original content is available. */
  setOriginalOnly(): void {
    this.mode.set('original');
    this.canToggle.set(false);
  }

  toggle(): void {
    if (!this.canToggle()) return;
    this.mode.set(this.mode() === 'reader' ? 'original' : 'reader');
  }
}
