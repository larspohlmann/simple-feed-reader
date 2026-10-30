import { Injectable, computed, inject } from '@angular/core';
import { AccountIdentity } from '../../core/auth/account-identity';
import { ListOrderService } from './list-order.service';
import { Selection, isDirectSearch, withListOrder, withUnreadPreference } from '../query/query';
import { UnreadFilterService } from './unread-filter.service';
import { SearchUnreadFilter } from './search-unread-filter.service';

/** The list a URL names, as this account reads it: neither the unread filter nor the
 *  order rides in the URL, so both are applied here. */
@Injectable({ providedIn: 'root' })
export class ListPreferences {
  private readonly unreadFilter = inject(UnreadFilterService);
  private readonly searchUnreadFilter = inject(SearchUnreadFilter);
  private readonly listOrder = inject(ListOrderService);

  readonly ready = inject(AccountIdentity).settled;

  /** Every preference as one value, so a flip watcher never keeps its own list of them. */
  readonly values = computed(
    () =>
      [
        this.unreadFilter.unreadOnly(),
        this.searchUnreadFilter.unreadOnly(),
        this.listOrder.oldestFirstViews(),
      ] as const,
    {
      equal: (left, right) => left[0] === right[0] && left[1] === right[1] && left[2] === right[2],
    },
  );

  appliedTo(selection: Selection): Selection {
    return withListOrder(
      withUnreadPreference(selection, this.unreadOnlyFor(selection)),
      this.listOrder.orderFor(selection),
    );
  }

  setUnreadOnly(selection: Selection, unreadOnly: boolean): void {
    if (isDirectSearch(selection)) {
      this.searchUnreadFilter.set(unreadOnly);
      return;
    }
    this.unreadFilter.set(unreadOnly);
  }

  /** Called whenever the reader shows anything but a direct search, so the next one opens on All posts. */
  endSearch(): void {
    this.searchUnreadFilter.reset();
  }

  private unreadOnlyFor(selection: Selection): boolean {
    return isDirectSearch(selection)
      ? this.searchUnreadFilter.unreadOnly()
      : this.unreadFilter.unreadOnly();
  }
}
