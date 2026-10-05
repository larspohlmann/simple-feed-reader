import { Injector, Signal, afterNextRender, effect, inject, signal } from '@angular/core';
import { EntryDto } from '../../models';
import { articleOverflowsViewport, readingProgress } from '../../article/reading/reading-progress';
import { estimatedListBottom } from './list-length';

export interface ListProgressRailOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly rail: () => HTMLElement | undefined;
  readonly entries: Signal<EntryDto[]>;
  readonly shownEntries: Signal<number>;
  readonly hasMore: Signal<boolean>;
  /** The list's entry count from its header, or null when it has none. */
  readonly total: Signal<number | null>;
  readonly loading: Signal<boolean>;
}

/** The list's length cue on a phone (#1392). Painted straight onto the rail
 *  from the outside-zone scroll handler, so scrolling costs no change detection.
 *  Built in a field initializer, so its effects are created there. */
export class ListProgressRail {
  private readonly injector = inject(Injector);
  readonly overflows = signal(false);
  private highestTotal = 0;

  private readonly _resetOnLoad = effect(() => {
    if (this.options.loading()) this.highestTotal = 0;
  });

  private readonly _paintAfterRender = effect(() => {
    this.options.entries();
    this.options.shownEntries();
    this.options.hasMore();
    this.options.total();
    this.overflows();
    afterNextRender(() => this.paint(), { injector: this.injector });
  });

  constructor(private readonly options: ListProgressRailOptions) {}

  readonly paint = (): void => {
    const scroller = this.options.scroller();
    if (!scroller) return;
    const bottom = this.estimatedBottom(scroller);
    const overflows = bottom !== null && articleOverflowsViewport(bottom, scroller.clientHeight);
    this.overflows.set(overflows);
    const rail = this.options.rail();
    if (!overflows || !rail) return;
    const fraction = readingProgress(scroller.scrollTop, scroller.clientHeight, bottom);
    rail.style.setProperty('--rail-fill', String(fraction * 100));
  };

  private estimatedBottom(scroller: HTMLElement): number | null {
    const slots = scroller.querySelectorAll<HTMLElement>('.row-slot');
    if (slots.length === 0) return null;
    const origin = scroller.getBoundingClientRect().top - scroller.scrollTop;
    return estimatedListBottom({
      rowsTop: slots[0].getBoundingClientRect().top - origin,
      rowsBottom: slots[slots.length - 1].getBoundingClientRect().bottom - origin,
      shownEntries: this.options.shownEntries(),
      loadedEntries: this.options.entries().length,
      totalEntries: this.knownTotal(),
      hasMore: this.options.hasMore(),
    });
  }

  private knownTotal(): number | null {
    this.highestTotal = Math.max(this.highestTotal, this.options.total() ?? 0);
    return this.highestTotal > 0 ? this.highestTotal : null;
  }
}
