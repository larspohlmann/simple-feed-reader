import { Injectable, computed, inject, signal } from '@angular/core';
import { AccountIdentity } from '../../core/auth/account-identity';
import { ListOrderService } from './list-order.service';
import {
  Selection,
  isDirectSearch,
  withListOrder,
  withTrendingWindow,
  withUnreadPreference,
} from '../query/query';
import { UnreadFilterService } from './unread-filter.service';
import { TrendingWindowService } from '../trending/trending-window.service';

/** The list a URL names, as this account reads it: neither the unread filter, the order
 *  nor the Trending window rides in the URL, so all three are applied here. */
@Injectable({ providedIn: 'root' })
export class ListPreferences {
  private readonly unreadFilter = inject(UnreadFilterService);
  private readonly listOrder = inject(ListOrderService);
  private readonly trendingWindow = inject(TrendingWindowService);

  /** A search hunts for a post, often one already read, so it never takes the account's
   *  unread filter: each opens on All posts, and a flip lasts until the search ends (#1922). */
  private readonly searchUnreadOnly = signal(false);
  /** Counts the user's flips inside a search, so `endSearch()` reads as no flip. */
  private readonly searchUnreadFlips = signal(0);

  readonly ready = inject(AccountIdentity).settled;

  /** Every preference the user set, as one value, so a flip watcher never keeps its own list of them. */
  readonly values = computed(
    () =>
      [
        this.unreadFilter.unreadOnly(),
        this.searchUnreadFlips(),
        this.listOrder.oldestFirstViews(),
        this.trendingWindow.window(),
      ] as const,
    { equal: (left, right) => left.every((value, index) => value === right[index]) },
  );

  appliedTo(selection: Selection): Selection {
    const ordered = withListOrder(
      withUnreadPreference(selection, this.unreadOnlyFor(selection)),
      this.listOrder.orderFor(selection),
    );
    return withTrendingWindow(ordered, this.trendingWindow.window());
  }

  setUnreadOnly(selection: Selection, unreadOnly: boolean): void {
    if (!isDirectSearch(selection)) {
      this.unreadFilter.set(unreadOnly);
      return;
    }
    this.searchUnreadOnly.set(unreadOnly);
    this.searchUnreadFlips.update((flips) => flips + 1);
  }

  endSearch(): void {
    this.searchUnreadOnly.set(false);
  }

  private unreadOnlyFor(selection: Selection): boolean {
    return isDirectSearch(selection) ? this.searchUnreadOnly() : this.unreadFilter.unreadOnly();
  }
}
