import { DestroyRef, ElementRef, Injectable, Signal, inject, signal } from '@angular/core';
import { ListScrollMemory } from '../../scroll/list-scroll-memory';

// Article scroll-restore settle: re-assert the target for at most this many frames
// per content render, stopping early once the height has held steady this long.
const ARTICLE_SETTLE_FRAMES = 60;
const ARTICLE_SETTLE_STABLE = 4;

/** Article scroll restore: a resume-reload reopens the entry at the top; this
 *  re-seats it where the user was. The pending target holds until it lands
 *  (re-asserted across the content swap/image loads) or the user scrolls. */
@Injectable()
export class ArticleScrollRestore {
  private readonly layer = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly scroll = inject(ListScrollMemory);
  private scroller: Signal<HTMLElement | undefined> = signal(undefined);

  private pendingRestore: { id: number; top: number } | null = null;
  private restoreRaf = 0;

  constructor() {
    // A real wheel gesture hands scrolling back to the user, cancelling any
    // in-flight restore so it never fights them.
    const abortRestore = (): void => this.abort();
    this.layer.addEventListener('wheel', abortRestore, { passive: true });
    inject(DestroyRef).onDestroy(() => {
      this.layer.removeEventListener('wheel', abortRestore);
      this.cancelRestore();
    });
  }

  connect(scroller: Signal<HTMLElement | undefined>): void {
    this.scroller = scroller;
  }

  /** Arm a restore for a newly opened entry if a position is remembered for it. */
  arm(entryId: number | null): void {
    this.cancelRestore();
    if (entryId === null) {
      this.pendingRestore = null;
      return;
    }
    const savedTop = this.scroll.readEntry(entryId);
    this.pendingRestore = savedTop > 0 ? { id: entryId, top: savedTop } : null;
  }

  /** Content just (re-)rendered — re-seat a pending restore for the open entry. */
  reseat(currentId: () => number | undefined): void {
    if (this.pendingRestore?.id === currentId()) this.startRestore(currentId);
  }

  /** The user (or a jump) takes over; stop restoring. */
  abort(): void {
    this.pendingRestore = null;
  }

  /** Remember the reading position so a resume-reload can restore it. Skipped
   *  while a restore is in flight: the content may still be short and its
   *  clamped scrollTop would overwrite the good target. */
  remember(id: number | undefined, top: number): void {
    if (id != null && !this.pendingRestore) this.scroll.saveEntry(id, top);
  }

  /**
   * Re-assert the pending scroll target across the frames where the article's
   * height is still settling (original→reader swap, images loading), stopping
   * once the height holds steady, the budget is spent, or the user takes over.
   */
  private startRestore(currentId: () => number | undefined): void {
    this.cancelRestore();
    const pending = this.pendingRestore;
    const scroller = this.scroller();
    if (!pending || !scroller) return;
    // Rough landing right away so the restore holds even where rAF is throttled
    // (e.g. a backgrounded tab); the loop below then refines it as height settles.
    scroller.scrollTop = pending.top;
    if (typeof requestAnimationFrame === 'undefined') return;
    let frames = 0;
    let stable = 0;
    let lastHeight = -1;
    const step = (): void => {
      const pending = this.pendingRestore;
      if (!pending || pending.id !== currentId()) return; // aborted or entry changed
      scroller.scrollTop = pending.top;
      const height = scroller.scrollHeight;
      stable = height === lastHeight ? stable + 1 : 0;
      lastHeight = height;
      if (++frames < ARTICLE_SETTLE_FRAMES && stable < ARTICLE_SETTLE_STABLE) {
        this.restoreRaf = requestAnimationFrame(step);
      }
    };
    this.restoreRaf = requestAnimationFrame(step);
  }

  private cancelRestore(): void {
    if (this.restoreRaf && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(this.restoreRaf);
    }
    this.restoreRaf = 0;
  }
}
