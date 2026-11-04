import { FoldedGroup, entriesAboveFold, foldedGroupTailsAbove, measureEntries } from './above-fold';

export interface ListAboveFoldOptions {
  readonly scroller: () => HTMLElement | undefined;
  readonly header: () => HTMLElement | undefined;
  readonly groups: () => readonly FoldedGroup[];
}

/** Which rendered entries the reader has scrolled past, measured against the fold: the lower of the
 *  scroller's top and the floating list header's bottom. */
export class ListAboveFold {
  constructor(private readonly options: ListAboveFoldOptions) {}

  hasEntryAbove(scroller: HTMLElement): boolean {
    const first = scroller.querySelector('[data-entry-id]');
    return !!first && first.getBoundingClientRect().bottom <= this.foldTop(scroller);
  }

  entryIds(): number[] {
    const scroller = this.options.scroller();
    if (!scroller) return [];
    const measured = measureEntries(scroller);
    const above = entriesAboveFold(measured, this.foldTop(scroller));
    const rendered = new Set(measured.map((measurement) => measurement.id));
    return [...above, ...foldedGroupTailsAbove(new Set(above), rendered, this.options.groups())];
  }

  private foldTop(scroller: HTMLElement): number {
    const header = this.options.header()?.getBoundingClientRect().bottom ?? 0;
    return Math.max(scroller.getBoundingClientRect().top, header);
  }
}
