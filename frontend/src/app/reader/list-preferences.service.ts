import { Injectable, computed, inject } from '@angular/core';
import { AccountIdentity } from '../core/auth/account-identity';
import { ListOrderService } from './list-order.service';
import { Selection, withListOrder, withUnreadPreference } from './query';
import { UnreadFilterService } from './unread-filter.service';

/** The list a URL names, as this account reads it: neither the unread filter nor the
 *  order rides in the URL, so both are applied here. */
@Injectable({ providedIn: 'root' })
export class ListPreferences {
  private readonly unreadFilter = inject(UnreadFilterService);
  private readonly listOrder = inject(ListOrderService);

  readonly ready = inject(AccountIdentity).settled;

  /** Both preferences as one value, so a flip watcher never keeps its own list of them. */
  readonly values = computed(
    () => [this.unreadFilter.unreadOnly(), this.listOrder.oldestFirstViews()] as const,
    { equal: (left, right) => left[0] === right[0] && left[1] === right[1] },
  );

  appliedTo(selection: Selection): Selection {
    return withListOrder(
      withUnreadPreference(selection, this.unreadFilter.unreadOnly()),
      this.listOrder.orderFor(selection),
    );
  }
}
