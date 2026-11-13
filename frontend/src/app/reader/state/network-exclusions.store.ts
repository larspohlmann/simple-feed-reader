import { inject, signal } from '@angular/core';
import { EMPTY, catchError, of } from 'rxjs';
import { onIdentityChange } from '../../core/auth/session-identity';
import { SocialNetworkName } from '../../core/social-network';
import { toggled } from '../../core/toggled-set';
import { NetworkExclusionSurface, NetworkExclusionsApi } from '../network-exclusions-api';
import { sameMembers } from './same-members';
import { SubscriptionsStore } from './subscriptions.store';

/** The social networks the user keeps out of one surface; every other network's feeds count. */
export abstract class NetworkExclusionsStore {
  private readonly api = inject(NetworkExclusionsApi);
  private readonly subscriptions = inject(SubscriptionsStore);

  protected abstract readonly surface: NetworkExclusionSurface;

  private readonly stored = signal<ReadonlySet<SocialNetworkName>>(new Set(), {
    equal: sameMembers,
  });
  readonly excludedNetworks = this.stored.asReadonly();

  constructor() {
    onIdentityChange(() => this.stored.set(new Set()));
  }

  load(): void {
    this.api
      .excludedNetworks(this.surface)
      .pipe(catchError(() => EMPTY))
      .subscribe((response) => this.stored.set(new Set(response.excludedNetworks)));
  }

  toggleNetwork(network: SocialNetworkName): void {
    const excluded = this.excludedNetworks().has(network);
    this.stored.update((current) => toggled(current, network));
    const write = excluded
      ? this.api.include(this.surface, network)
      : this.api.exclude(this.surface, network);
    write.pipe(catchError(() => of(undefined))).subscribe(() => {
      this.load();
      this.refreshSurface();
    });
  }

  /** Re-learns what a landed switch changes: the badges, and for a surface with its own list, that list. */
  protected refreshSurface(): void {
    this.subscriptions.reloadCountsQuietly();
  }
}
