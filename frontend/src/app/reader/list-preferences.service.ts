import { Injectable, inject } from '@angular/core';
import { UserDeviceStorage } from '../core/user-device-storage';
import { ListOrderService } from './list-order.service';
import { Selection, withListOrder, withUnreadPreference } from './query';
import { UnreadFilterService } from './unread-filter.service';

/** The list a URL names, as this account reads it: neither the unread filter nor the
 *  order rides in the URL, so both are applied here. */
@Injectable({ providedIn: 'root' })
export class ListPreferences {
  private readonly unreadFilter = inject(UnreadFilterService);
  private readonly listOrder = inject(ListOrderService);

  readonly ready = inject(UserDeviceStorage).ready;

  appliedTo(selection: Selection): Selection {
    return withListOrder(
      withUnreadPreference(selection, this.unreadFilter.unreadOnly()),
      this.listOrder.orderFor(selection),
    );
  }
}
