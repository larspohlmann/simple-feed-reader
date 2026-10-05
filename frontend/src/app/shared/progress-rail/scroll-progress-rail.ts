import {
  Injector,
  Signal,
  afterNextRender,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { overflowsViewport, scrollProgress } from './scroll-progress';

export interface ScrollProgressRailOptions {
  readonly isWide: Signal<boolean>;
  /** Where the content ends in the scroller's scroll coordinates, or null when unknown. */
  readonly contentBottom: (scroller: HTMLElement) => number | null;
  /** Changes whenever the content's end may have moved; the rail repaints once rendered. */
  readonly layout: Signal<unknown>;
}

/** A scroller's length-and-position cue (#238), for the article and the paged list
 *  alike: the scroller (`appScrollProgress`) and the rail (`app-progress-rail`) attach
 *  themselves, and `paint()` writes the fill without change detection (#501). */
export class ScrollProgressRail {
  private readonly injector = inject(Injector);
  private readonly scroller = signal<HTMLElement | undefined>(undefined);
  private rail?: HTMLElement;

  readonly overflows = signal(false);

  /** A wide layout keeps its scrollbar and gets a hairline along the bottom; a phone
   *  gets a rail on the right edge in place of the scrollbar it withholds. */
  readonly horizontal = computed(() => this.options.isWide());

  /** By layout, not by `overflows`: toggling a classic scrollbar resizes the content,
   *  which could flip `overflows` back and forth. */
  readonly replacesScrollbar = computed(() => !this.horizontal());

  private readonly _paintAfterRender = effect(() => {
    this.options.layout();
    afterNextRender(() => this.paint(), { injector: this.injector });
  });

  private readonly _paintOnResize = effect((onCleanup) => {
    const scroller = this.scroller();
    if (!scroller || typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(() => this.paint());
    observer.observe(scroller);
    onCleanup(() => observer.disconnect());
  });

  constructor(private readonly options: ScrollProgressRailOptions) {}

  attachScroller(scroller: HTMLElement): void {
    this.scroller.set(scroller);
  }

  detachScroller(scroller: HTMLElement): void {
    if (untracked(this.scroller) === scroller) this.scroller.set(undefined);
  }

  attachRail(rail: HTMLElement): void {
    this.rail = rail;
    untracked(() => this.paint());
  }

  detachRail(rail: HTMLElement): void {
    if (this.rail === rail) this.rail = undefined;
  }

  readonly paint = (): void => {
    const scroller = untracked(this.scroller);
    if (!scroller) return;
    const bottom = this.options.contentBottom(scroller);
    const overflows = bottom !== null && overflowsViewport(bottom, scroller.clientHeight);
    this.overflows.set(overflows);
    if (!overflows || !this.rail) return;
    const fraction = scrollProgress(scroller.scrollTop, scroller.clientHeight, bottom);
    this.rail.style.setProperty('--rail-fill', String(fraction * 100));
  };
}
