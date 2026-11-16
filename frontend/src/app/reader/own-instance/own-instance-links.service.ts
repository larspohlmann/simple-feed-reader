import { computed, inject, Injectable, Signal } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { instanceOrigin, ownInstancePostUrl } from './own-instance-url';

@Injectable({ providedIn: 'root' })
export class OwnInstanceLinks {
  private readonly subscriptions = inject(SubscriptionsStore);

  readonly instance: Signal<string | null> = computed(() => {
    const account = this.subscriptions.socialAccounts().find((ref) => ref.network === 'mastodon');
    return account === undefined ? null : instanceOrigin(account.serviceUrl);
  });

  open(address: string, network: SocialNetworkName | null): string {
    return network === 'mastodon' ? ownInstancePostUrl(address, this.instance()) : address;
  }
}
