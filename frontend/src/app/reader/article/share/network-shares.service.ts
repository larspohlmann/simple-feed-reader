import { Injectable, inject } from '@angular/core';
import { SharingService } from '../../../core/preferences/sharing.service';
import { EntryRowCache } from '../../state/entry-row-cache';
import { countFirstShare } from '../../state/first-share';
import { SubscriptionsStore } from '../../state/subscriptions.store';

/** Shows the share the server records for a repost or quote of the entry's own post, with no request of its own. */
@Injectable({ providedIn: 'root' })
export class NetworkShares {
  private readonly rows = inject(EntryRowCache);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly sharing = inject(SharingService);

  reflect(entryId: number): void {
    if (!this.sharing.recordsShares()) return;
    countFirstShare(this.subscriptions, this.rows.entry(entryId));
    this.rows.markSharedLocally(entryId);
  }
}
