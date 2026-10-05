import { Signal, effect } from '@angular/core';
import { EntryDto } from '../../models';
import { estimatedListBottom } from './list-length';

export interface EstimatedListBottomOptions {
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

/** Where a paged list would end with every entry loaded (#1392): the progress rail's
 *  content end for the list. Built in a field initializer, for its effect. */
export class EstimatedListBottom {
  private highestTotal = 0;

  private readonly _resetOnLoadEdge = effect(() => {
    this.options.loading();
    this.highestTotal = 0;
  });

  constructor(private readonly options: EstimatedListBottomOptions) {}

  readonly layoutChanges = (): void => {
    this.options.rendered();
    this.options.shownEntries();
    this.options.complete();
    this.options.total();
    this.options.loadedTotal();
  };

  readonly measure = (scroller: HTMLElement): number | null => {
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
  };

  /** Keeps the highest count since the load began: reading lowers an unread count
   *  while the read rows stay on screen. */
  private raiseHeldTotal(): number | null {
    this.highestTotal = Math.max(this.highestTotal, this.options.total() ?? 0);
    return this.highestTotal > 0 ? this.highestTotal : null;
  }
}
