import { Injector, Signal, afterNextRender, effect, inject, signal } from '@angular/core';
import { EntryDto } from '../../models';
import { articleOverflowsViewport, readingProgress } from '../../article/reading/reading-progress';
import { estimatedListBottom } from './list-length';

export interface ListProgressRailOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly rail: () => HTMLElement | undefined;
  /** The entries in the DOM, which trail the loaded ones while a page is revealed. */
  readonly rendered: Signal<EntryDto[]>;
  readonly shownEntries: Signal<number>;
  readonly complete: Signal<boolean>;
  /** The list's entry count from its header, or null when it has none. */
  readonly total: Signal<number | null>;
  /** The exact length once every page is loaded, which overrides the header. */
  readonly loadedTotal: Signal<number | null>;
  readonly loading: Signal<boolean>;
}

/** The list's length cue (#1392), written straight onto the rail from the
 *  outside-zone scroll handler. Built in a field initializer, for its effects. */
export class ListProgressRail {
  private readonly injector = inject(Injector);
  readonly overflows = signal(false);
  private highestTotal = 0;

  private readonly _resetOnLoadEdge = effect(() => {
    this.options.loading();
    this.highestTotal = 0;
  });

  private readonly _paintAfterRender = effect(() => {
    this.options.rendered();
    this.options.shownEntries();
    this.options.complete();
    this.options.total();
    this.options.loadedTotal();
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
      renderedEntries: this.options.rendered().length,
      totalEntries: this.options.loadedTotal() ?? this.raiseHeldTotal(),
      complete: this.options.complete(),
    });
  }

  /** Keeps the highest count since the load began: reading lowers an unread count
   *  while the read rows stay on screen. */
  private raiseHeldTotal(): number | null {
    this.highestTotal = Math.max(this.highestTotal, this.options.total() ?? 0);
    return this.highestTotal > 0 ? this.highestTotal : null;
  }
}
