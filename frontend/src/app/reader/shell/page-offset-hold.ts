import { DOCUMENT } from '@angular/common';
import { DestroyRef, NgZone, inject } from '@angular/core';
import { ScrollHosts } from '../../shared/scroll-host/scroll-hosts.service';
import type { ScrollHost } from '../../shared/scroll-host/scroll-host';

export const HOLD_MS = 600;
const SAME_OFFSET_PX = 2;
const USER_TAKEOVER_EVENTS = ['touchstart', 'wheel', 'pointerdown', 'keydown'];

export interface PageOffsetHandoff {
  readonly from: number;
  readonly to: number;
}

const sameOffset = (first: number, second: number): boolean =>
  Math.abs(first - second) <= SAME_OFFSET_PX;

/** Writes a layer switch's page offset and holds it there: iOS WebKit puts the old offset back
 *  10–220 ms after a write made in the frame the switch resized the document (#2074), and a layer
 *  not in the page yet cuts the write short (#2088). Built in an injection context. */
export class PageOffsetHold {
  private readonly view = inject(DOCUMENT).defaultView ?? window;
  private readonly zone = inject(NgZone);
  private readonly scrollHosts = inject(ScrollHosts);
  private current: AbortController | null = null;

  constructor(private readonly page: ScrollHost) {
    inject(DestroyRef).onDestroy(() => this.release());
  }

  /** Undoes a return to `from` until HOLD_MS pass, the user takes the page, or the app writes
   *  another offset. */
  handOff({ from, to }: PageOffsetHandoff): void {
    this.release();
    this.page.scrollTop = to;
    let cutShort = !sameOffset(this.page.scrollTop, to);
    const handoff = new AbortController();
    this.current = handoff;
    const startedAt = this.view.performance.now();
    let frame = 0;
    const step = (now: number): void => {
      if (now - startedAt > HOLD_MS) {
        handoff.abort();
        return;
      }
      const landsNow = cutShort && this.fits(to);
      if (landsNow) cutShort = false;
      if (landsNow || this.returnedTo(from, to)) this.page.scrollTop = to;
      frame = this.view.requestAnimationFrame(step);
    };
    const stopObservingWrites = this.scrollHosts.observePageWrites((written) => {
      if (!sameOffset(written, to)) handoff.abort();
    });
    handoff.signal.addEventListener('abort', () => {
      this.view.cancelAnimationFrame(frame);
      stopObservingWrites();
    });
    this.zone.runOutsideAngular(() => {
      USER_TAKEOVER_EVENTS.forEach((type) =>
        this.view.addEventListener(type, () => handoff.abort(), {
          passive: true,
          capture: true,
          signal: handoff.signal,
        }),
      );
      frame = this.view.requestAnimationFrame(step);
    });
  }

  private release(): void {
    this.current?.abort();
    this.current = null;
  }

  /** Back at `from`, or at the end of the page when `from` no longer fits in it. */
  private returnedTo(from: number, to: number): boolean {
    const current = this.page.scrollTop;
    if (sameOffset(current, to)) return false;
    return sameOffset(current, Math.min(from, this.end()));
  }

  private fits(top: number): boolean {
    return this.end() + SAME_OFFSET_PX >= top;
  }

  private end(): number {
    return Math.max(0, this.page.scrollHeight - this.page.clientHeight);
  }
}
