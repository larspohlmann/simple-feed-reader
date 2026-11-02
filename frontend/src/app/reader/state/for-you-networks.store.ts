import { Injectable, inject, signal } from '@angular/core';
import { EMPTY, catchError, of } from 'rxjs';
import { onIdentityChange } from '../../core/auth/session-identity';
import { SocialNetworkName } from '../../core/social-network';
import { ReaderApi } from '../reader-api';
import { sameMembers } from './same-members';
import { SubscriptionsStore } from './subscriptions.store';

/** The social networks the user keeps out of For you; every other network's feeds count. */
@Injectable({ providedIn: 'root' })
export class ForYouNetworksStore {
  private readonly api = inject(ReaderApi);
  private readonly subscriptions = inject(SubscriptionsStore);

  private readonly stored = signal<ReadonlySet<SocialNetworkName>>(new Set(), {
    equal: sameMembers,
  });
  readonly excludedNetworks = this.stored.asReadonly();

  constructor() {
    onIdentityChange(() => this.stored.set(new Set()));
  }

  load(): void {
    this.api
      .forYouExcludedNetworks()
      .pipe(catchError(() => EMPTY))
      .subscribe((response) => this.stored.set(new Set(response.excludedNetworks)));
  }

  toggleNetwork(network: SocialNetworkName): void {
    const excluded = this.excludedNetworks().has(network);
    this.stored.update((current) => {
      const next = new Set(current);
      if (excluded) next.delete(network);
      else next.add(network);
      return next;
    });
    const write = excluded
      ? this.api.includeNetworkInForYou(network)
      : this.api.excludeNetworkFromForYou(network);
    write.pipe(catchError(() => of(undefined))).subscribe(() => {
      this.load();
      this.subscriptions.reloadCountsQuietly();
    });
  }
}
