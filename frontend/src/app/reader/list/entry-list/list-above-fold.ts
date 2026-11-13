import type { ScrollHost } from '../../../shared/scroll-host/scroll-host';
import { FoldedGroup, entriesAboveFold, foldedGroupTailsAbove, measureEntries } from './above-fold';

export interface ListAboveFoldOptions {
  readonly scroller: () => ScrollHost | undefined;
  readonly header: () => HTMLElement | undefined;
  readonly groups: () => readonly FoldedGroup[];
}

/** Which rendered entries the reader has scrolled past, measured against the fold: the lower of the
 *  scroller's top and the floating list header's bottom. */
export class ListAboveFold {
  constructor(private readonly options: ListAboveFoldOptions) {}

  entryIds(): number[] {
    const scroller = this.options.scroller();
    if (!scroller) return [];
    const measured = measureEntries(scroller.content);
    const above = entriesAboveFold(measured, this.foldTop(scroller));
    const rendered = new Set(measured.map((measurement) => measurement.id));
    return [...above, ...foldedGroupTailsAbove(new Set(above), rendered, this.options.groups())];
  }

  private foldTop(scroller: ScrollHost): number {
    const header = this.options.header()?.getBoundingClientRect().bottom ?? 0;
    return Math.max(scroller.viewportTop(), header);
  }
}
