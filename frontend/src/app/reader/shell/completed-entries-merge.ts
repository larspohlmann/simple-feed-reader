import { Injectable, inject } from '@angular/core';
import { EntryBodyService } from '../article/content/entry-body.service';
import { CompletedEntryDto } from '../state/completed-entry';
import { EntriesStore } from '../state/entries.store';
import { ReaderRouteState } from './reader-route-state.service';

/** Folds completed rows back into the reader: the list, the open entry's body, and the cached
 *  bodies of the others, which went stale with them. */
@Injectable()
export class CompletedEntriesMerge {
  private readonly store = inject(EntriesStore);
  private readonly bodies = inject(EntryBodyService);
  private readonly routeState = inject(ReaderRouteState);

  merge(rows: CompletedEntryDto[], sent: number[]): void {
    this.store.mergeCompleted(rows);
    const openId = this.routeState.openEntry()?.id;
    for (const row of rows) {
      if (row.pending || !sent.includes(row.id)) continue;
      if (row.id === openId) this.bodies.refresh(row.id);
      else this.bodies.forget(row.id);
    }
  }
}
