import { computed, inject, Injectable, Signal } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { instanceOrigin, LinkFacts, OwnInstances, ownInstanceUrl } from './own-instance-url';

@Injectable({ providedIn: 'root' })
export class OwnInstanceLinks {
  private readonly subscriptions = inject(SubscriptionsStore);

  readonly instances: Signal<OwnInstances> = computed(() => ({
    mastodon: this.findInstance('mastodon'),
    lemmy: this.findInstance('lemmy'),
    lemmyHosts: new Set(this.subscriptions.lemmyHosts()),
  }));

  open(address: string, facts: LinkFacts = {}): string {
    return ownInstanceUrl(address, facts, this.instances());
  }

  private findInstance(network: SocialNetworkName): string | null {
    const account = this.subscriptions.socialAccounts().find((ref) => ref.network === network);
    return account === undefined ? null : instanceOrigin(account.serviceUrl);
  }
}
