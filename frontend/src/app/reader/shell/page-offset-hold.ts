import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, NgZone, inject } from '@angular/core';
import type { ScrollHost } from '../../shared/scroll-host/scroll-host';
import { observePageWrites } from '../../shared/scroll-host/page-scroll-host';

export const HOLD_MS = 600;
const SAME_OFFSET_PX = 2;
const USER_TAKEOVER_EVENTS = ['touchstart', 'wheel', 'pointerdown', 'keydown'];

/** Holds the page at a handed-off offset. iOS WebKit puts the old offset back 10–220 ms after
 *  a write made in the frame a layer switch resized the document (#2074). */
@Injectable()
export class PageOffsetHold {
  private readonly document = inject(DOCUMENT);
  private readonly view = this.document.defaultView ?? window;
  private readonly zone = inject(NgZone);
  private releaseHold: () => void = () => undefined;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.release());
  }

  /** Writes `top` through `page`, then undoes any return to the offset it replaced, until HOLD_MS
   *  pass, the user takes the page, or the app writes another offset. */
  hold(page: ScrollHost, top: number): void {
    this.release();
    const replaced = page.scrollTop;
    page.scrollTop = top;
    const startedAt = this.view.performance.now();
    const release = (): void => this.release();
    let frame = 0;
    const step = (): void => {
      if (this.view.performance.now() - startedAt > HOLD_MS) {
        this.release();
        return;
      }
      if (this.returnedTo(page, replaced, top)) page.scrollTop = top;
      frame = this.view.requestAnimationFrame(step);
    };
    const stopObservingWrites = observePageWrites((written) => {
      if (!this.near(written, top)) release();
    });
    this.zone.runOutsideAngular(() => {
      USER_TAKEOVER_EVENTS.forEach((type) =>
        this.view.addEventListener(type, release, { passive: true, capture: true }),
      );
      frame = this.view.requestAnimationFrame(step);
    });
    this.releaseHold = () => {
      this.view.cancelAnimationFrame(frame);
      stopObservingWrites();
      USER_TAKEOVER_EVENTS.forEach((type) =>
        this.view.removeEventListener(type, release, { capture: true }),
      );
    };
  }

  private release(): void {
    this.releaseHold();
    this.releaseHold = () => undefined;
  }

  /** The old offset back, or the end of the page when the old offset no longer fits in it. */
  private returnedTo(page: ScrollHost, replaced: number, top: number): boolean {
    const current = page.scrollTop;
    if (this.near(current, top)) return false;
    const end = Math.max(0, page.scrollHeight - page.clientHeight);
    return this.near(current, Math.min(replaced, end));
  }

  private near(offset: number, other: number): boolean {
    return Math.abs(offset - other) <= SAME_OFFSET_PX;
  }
}
