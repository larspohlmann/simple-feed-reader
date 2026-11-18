import { Signal, signal } from '@angular/core';
import { queryFromSelection } from '../query/entry-query';
import { Selection } from '../query/query';
import { EntriesStore } from './entries.store';

/** The list one host shows (#2329): the store holding its rows, what it lists, and the rows leaving it. The
 *  reader shell provides the route's list; a Trending story's layer provides its own over it. */
export abstract class ListContext {
  abstract readonly entries: EntriesStore;
  abstract readonly selection: Signal<Selection>;

  private readonly leaving = signal<ReadonlySet<number>>(new Set());
  /** Rows fading out of a saved view or Trending; they stay in the list data so the magazine plan holds. */
  readonly leavingIds = this.leaving.asReadonly();

  clearLeaving(): void {
    this.leaving.set(new Set());
  }

  markLeaving(id: number): void {
    this.leaving.update((ids) => new Set(ids).add(id));
  }

  stayInList(id: number): void {
    this.leaving.update((ids) => {
      const remaining = new Set(ids);
      remaining.delete(id);
      return remaining;
    });
  }

  /** Shows the list again once the server has marked all of it read. */
  showMarkedAllRead(): void {
    this.entries.load(queryFromSelection(this.selection()));
  }
}
