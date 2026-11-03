import { Injectable, inject } from '@angular/core';
import { SharingService } from '../../../core/preferences/sharing.service';
import { EntriesStore } from '../../state/entries.store';
import { SubscriptionsStore } from '../../state/subscriptions.store';

/** Shows the share the server records for a repost or quote of the entry's own post, with no request of its own. */
@Injectable({ providedIn: 'root' })
export class NetworkShares {
  private readonly entries = inject(EntriesStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly sharing = inject(SharingService);

  reflect(entryId: number): void {
    if (!this.sharing.recordsShares()) return;
    if (this.entries.entry(entryId)?.isShared === false) this.subscriptions.bumpShared(1);
    this.entries.markSharedLocally(entryId);
  }
}
