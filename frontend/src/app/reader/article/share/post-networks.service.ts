import { Injectable, computed, inject } from '@angular/core';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../../core/social-network';
import { SubscriptionsStore } from '../../state/subscriptions.store';

export interface PostNetwork {
  readonly network: SocialNetworkName;
  readonly handles: readonly string[];
}

/** The networks the reader can post to, Bluesky first, with the handles of each one's connected accounts. */
@Injectable({ providedIn: 'root' })
export class PostNetworks {
  private readonly subscriptions = inject(SubscriptionsStore);

  readonly connected = computed((): PostNetwork[] => {
    const connected = this.subscriptions
      .socialAccounts()
      .filter((account) => account.status === 'connected');
    return SOCIAL_NETWORKS.flatMap((network) => {
      const handles = connected
        .filter((account) => account.network === network)
        .map((account) => account.handle);
      return handles.length === 0 ? [] : [{ network, handles }];
    });
  });
}
