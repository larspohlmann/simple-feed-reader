import { Injector, afterNextRender, computed, effect, inject, signal } from '@angular/core';
import { ProgressRailOrientation } from '../../shared/progress-rail/progress-rail.component';
import { LayoutService } from '../layout.service';
import { overflowsViewport, scrollProgress } from './scroll-progress';

export interface ScrollProgressRailOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly rail: () => HTMLElement | undefined;
  /** Where the content ends in the scroller's scroll coordinates, or null when unknown. */
  readonly contentBottom: (scroller: HTMLElement) => number | null;
  /** Reads every signal whose change moves the content's end, so the rail repaints once
   *  that change has rendered. */
  readonly layoutChanges: () => void;
}

/**
 * The scroller's length-and-position cue (#238, #1392), shared by the article and the
 * list; only where the content ends differs between them. `paint()` writes the fill
 * straight onto the rail, so a scroll handler outside the zone costs no change
 * detection (#501). Built in a field initializer, for its effects.
 */
export class ScrollProgressRail {
  private readonly injector = inject(Injector);
  private readonly screen = inject(LayoutService);

  readonly overflows = signal(false);

  /** A phone gets a rail on the right edge in place of the scrollbar it withholds;
   *  a wide layout keeps its scrollbar and gets a hairline along the bottom. */
  readonly orientation = computed<ProgressRailOrientation>(() =>
    this.screen.isWide() ? 'horizontal' : 'vertical',
  );

  readonly replacesScrollbar = computed(
    () => this.overflows() && this.orientation() === 'vertical',
  );

  private readonly _paintAfterRender = effect(() => {
    this.options.layoutChanges();
    this.overflows();
    this.orientation();
    afterNextRender(() => this.paint(), { injector: this.injector });
  });

  constructor(private readonly options: ScrollProgressRailOptions) {}

  readonly paint = (): void => {
    const scroller = this.options.scroller();
    if (!scroller) return;
    const bottom = this.options.contentBottom(scroller);
    const overflows = bottom !== null && overflowsViewport(bottom, scroller.clientHeight);
    this.overflows.set(overflows);
    const rail = this.options.rail();
    if (!overflows || !rail) return;
    const fraction = scrollProgress(scroller.scrollTop, scroller.clientHeight, bottom);
    rail.style.setProperty('--rail-fill', String(fraction * 100));
  };
}
