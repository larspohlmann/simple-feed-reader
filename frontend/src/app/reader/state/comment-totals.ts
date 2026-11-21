import { Injectable } from '@angular/core';
import { accountSignal } from '../../core/auth/session-identity';
import { EntryDto } from '../models';
import { ItemRef, buildItemKey } from './item-key';

const CAP = 50;

/** The totals of the comment threads the reader opened, so a list row shows what the open thread showed (#2433). */
@Injectable({ providedIn: 'root' })
export class CommentTotals {
  private readonly totals = accountSignal<ReadonlyMap<string, number>>(new Map());

  /** The stored count until the item's thread is in, its total after; null where the source counts no comments. */
  latestCount(entry: ItemRef & Pick<EntryDto, 'commentCount'>): number | null {
    if (entry.commentCount === null) return null;
    return this.totals().get(buildItemKey(entry)) ?? entry.commentCount;
  }

  record(item: ItemRef, total: number): void {
    const key = buildItemKey(item);
    this.totals.update((totals) => {
      if (totals.get(key) === total) return totals;
      const next = new Map(totals);
      next.delete(key);
      next.set(key, total);
      const oldest = next.size > CAP ? next.keys().next().value : undefined;
      if (oldest !== undefined) next.delete(oldest);
      return next;
    });
  }
}
