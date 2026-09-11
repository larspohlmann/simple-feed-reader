import { type FocusCurve, focusOpacityForSpan } from './reading-focus';

export interface ReadingFocusConfig {
  readonly scroller: HTMLElement;
  readonly blocks: () => HTMLElement[];
  readonly curve: FocusCurve;
  /** enabled && !isWide && !reduceMotion — read live, off the reactive graph. */
  readonly isActive: () => boolean;
  readonly runOutsideZone?: <T>(run: () => T) => T;
}

/**
 * Keeps each block's inline opacity in step with its distance from the scroll
 * centre. It observes the geometry it reads — the scroller and every block —
 * so it never enumerates the causes of a layout change.
 */
export class ReadingFocusApplier {
  private readonly runOutsideZone: <T>(run: () => T) => T;
  private readonly observer?: ResizeObserver;
  private readonly onScroll = (): void => this.schedule();
  private frame = 0;
  private destroyed = false;

  constructor(private readonly config: ReadingFocusConfig) {
    this.runOutsideZone = config.runOutsideZone ?? ((run) => run());
    config.scroller.addEventListener('scroll', this.onScroll, { passive: true });
    if (typeof ResizeObserver !== 'undefined') {
      this.observer = new ResizeObserver(() => this.schedule());
    }
    this.observe();
    this.schedule();
  }

  refresh(): void {
    this.observe();
    this.schedule();
  }

  /** Blank every block now and drop a pending pass — a disable must clear the
   *  same tick, not a frame later. */
  clear(): void {
    this.cancel();
    for (const block of this.config.blocks()) block.style.opacity = '';
  }

  destroy(): void {
    this.destroyed = true;
    this.cancel();
    this.observer?.disconnect();
    this.config.scroller.removeEventListener('scroll', this.onScroll);
  }

  private observe(): void {
    if (!this.observer) return;
    this.observer.disconnect();
    this.observer.observe(this.config.scroller);
    for (const block of this.config.blocks()) this.observer.observe(block);
  }

  private schedule(): void {
    if (this.destroyed || this.frame || typeof requestAnimationFrame === 'undefined') return;
    this.frame = this.runOutsideZone(() =>
      requestAnimationFrame(() => {
        this.frame = 0;
        this.recompute();
      }),
    );
  }

  private cancel(): void {
    if (this.frame && typeof cancelAnimationFrame !== 'undefined') cancelAnimationFrame(this.frame);
    this.frame = 0;
  }

  private recompute(): void {
    const { scroller, blocks, curve, isActive } = this.config;
    if (!isActive()) {
      for (const block of blocks()) block.style.opacity = '';
      return;
    }
    const viewport = scroller.clientHeight;
    const scrollerTop = scroller.getBoundingClientRect().top;
    for (const block of blocks()) {
      const rect = block.getBoundingClientRect();
      const top = rect.top - scrollerTop;
      block.style.opacity = String(focusOpacityForSpan(top, top + rect.height, viewport, curve));
    }
  }
}
