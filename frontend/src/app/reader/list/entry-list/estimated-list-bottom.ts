import { Signal, computed, effect } from '@angular/core';
import { EntryDto } from '../../models';
import { TitleCount } from '../list-header/list-header.component';
import { ListContent } from './list-content';
import { estimatedListBottom } from './list-length';

export interface EstimatedListBottomOptions {
  readonly content: ListContent;
  readonly entries: Signal<EntryDto[]>;
  readonly hasMore: Signal<boolean>;
  readonly titleCount: Signal<TitleCount>;
  readonly isSearch: Signal<boolean>;
  readonly loading: Signal<boolean>;
}

/** Where a paged list would end with every entry loaded (#1392): the progress rail's
 *  content end for the list. Built in a field initializer, for its effect. */
export class EstimatedListBottom {
  private highestTotal = 0;

  /** The header's count, unless a search (which has none) or nothing is counted yet. */
  private readonly headerTotal = computed(() => {
    const count = this.options.titleCount().value;
    return this.options.isSearch() || count === 0 ? null : count;
  });

  /** Everything but the row geometry that moves the list's end. */
  readonly layout = computed(() => ({
    shownEntries: this.options.content.renderedVisibleCount(),
    renderedEntries: this.options.content.rendered().length,
    complete: this.options.content.complete(),
    headerTotal: this.headerTotal(),
    loadedTotal: this.options.hasMore() ? null : this.options.entries().length,
  }));

  private readonly _resetOnLoadEdge = effect(() => {
    this.options.loading();
    this.highestTotal = 0;
  });

  constructor(private readonly options: EstimatedListBottomOptions) {}

  readonly measure = (scroller: HTMLElement): number | null => {
    const slots = scroller.querySelectorAll<HTMLElement>('.row-slot');
    if (slots.length === 0) return null;
    const layout = this.layout();
    const origin = scroller.getBoundingClientRect().top - scroller.scrollTop;
    return estimatedListBottom({
      rowsTop: slots[0].getBoundingClientRect().top - origin,
      rowsBottom: slots[slots.length - 1].getBoundingClientRect().bottom - origin,
      shownEntries: layout.shownEntries,
      renderedEntries: layout.renderedEntries,
      totalEntries: layout.loadedTotal ?? this.raiseHeldTotal(layout.headerTotal),
      complete: layout.complete,
    });
  };

  /** Keeps the highest count since the load began: reading lowers an unread count
   *  while the read rows stay on screen. */
  private raiseHeldTotal(headerTotal: number | null): number | null {
    this.highestTotal = Math.max(this.highestTotal, headerTotal ?? 0);
    return this.highestTotal > 0 ? this.highestTotal : null;
  }
}
