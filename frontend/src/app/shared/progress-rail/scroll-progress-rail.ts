import { Injector, Signal, afterNextRender, computed, effect, inject, signal } from '@angular/core';
import { overflowsViewport, scrollProgress } from './scroll-progress';

export type ProgressRailOrientation = 'vertical' | 'horizontal';

export interface ScrollProgressRailOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly isWide: Signal<boolean>;
  /** Where the content ends in the scroller's scroll coordinates, or null when unknown. */
  readonly contentBottom: (scroller: HTMLElement) => number | null;
  /** Reads every signal whose change moves the content's end, so the rail repaints once
   *  that change has rendered. */
  readonly layoutChanges: () => void;
}

/** A scroller's length-and-position cue (#238), for the article and the paged list
 *  alike. `paint()` writes straight onto the rail, so a scroll handler outside the
 *  zone costs no change detection (#501). Built in a field initializer. */
export class ScrollProgressRail {
  private readonly injector = inject(Injector);
  private rail?: HTMLElement;

  readonly overflows = signal(false);

  /** A phone gets a rail on the right edge in place of the scrollbar it withholds;
   *  a wide layout keeps its scrollbar and gets a hairline along the bottom. */
  readonly orientation = computed<ProgressRailOrientation>(() =>
    this.options.isWide() ? 'horizontal' : 'vertical',
  );

  /** Fixed by orientation, not by `overflows`: toggling a classic scrollbar resizes
   *  the content, which could flip `overflows` back and forth. */
  readonly replacesScrollbar = computed(() => this.orientation() === 'vertical');

  private readonly _paintAfterRender = effect(() => {
    this.options.layoutChanges();
    afterNextRender(() => this.paint(), { injector: this.injector });
  });

  private readonly _paintOnResize = effect((onCleanup) => {
    const scroller = this.options.scroller();
    if (!scroller || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(() => this.paint());
    observer.observe(scroller);
    onCleanup(() => observer.disconnect());
  });

  constructor(private readonly options: ScrollProgressRailOptions) {}

  /** The rail element registers itself, so a view never holds a reference to it. */
  attach(rail: HTMLElement): void {
    this.rail = rail;
    this.paint();
  }

  detach(rail: HTMLElement): void {
    if (this.rail === rail) this.rail = undefined;
  }

  readonly paint = (): void => {
    const scroller = this.options.scroller();
    if (!scroller) return;
    const bottom = this.options.contentBottom(scroller);
    const overflows = bottom !== null && overflowsViewport(bottom, scroller.clientHeight);
    this.overflows.set(overflows);
    if (!overflows || !this.rail) return;
    const fraction = scrollProgress(scroller.scrollTop, scroller.clientHeight, bottom);
    this.rail.style.setProperty('--rail-fill', String(fraction * 100));
  };
}
