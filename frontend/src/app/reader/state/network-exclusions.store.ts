import { InjectionToken, Signal, inject, signal } from '@angular/core';
import { EMPTY, catchError, of } from 'rxjs';
import { onIdentityChange } from '../../core/auth/session-identity';
import { SocialNetworkName } from '../../core/social-network';
import { toggled } from '../../core/toggled-set';
import { ExclusionSurface } from '../feed-exclusions';
import { NetworkExclusionSurface, NetworkExclusionsApi } from '../network-exclusions-api';
import { EntriesStore } from './entries.store';
import { sameMembers } from './same-members';
import { SubscriptionsStore } from './subscriptions.store';

/** A surface's stored network switches, whatever default its store keeps. */
export interface NetworkSwitchStore {
  readonly excludedNetworks: Signal<ReadonlySet<SocialNetworkName>>;
  toggleNetwork(network: SocialNetworkName): void;
}

/** The surfaces whose network switches have their own endpoints; saved searches keep theirs with the searches. */
export type NetworkExcludingSurface = Exclude<ExclusionSurface, 'savedSearches'>;

const ENDPOINT_SURFACE: Readonly<Record<NetworkExcludingSurface, NetworkExclusionSurface>> = {
  allItems: 'all-items',
  forYou: 'for-you',
  trending: 'trending',
};

/** The social networks the user keeps out of one surface; every other network's feeds count. */
export class NetworkExclusionsStore implements NetworkSwitchStore {
  private readonly api = inject(NetworkExclusionsApi);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly entries = inject(EntriesStore);

  private readonly stored = signal<ReadonlySet<SocialNetworkName>>(new Set(), {
    equal: sameMembers,
  });
  readonly excludedNetworks = this.stored.asReadonly();

  constructor(private readonly surface: NetworkExcludingSurface) {
    onIdentityChange(() => this.stored.set(new Set()));
  }

  load(): void {
    this.api
      .excludedNetworks(ENDPOINT_SURFACE[this.surface])
      .pipe(catchError(() => EMPTY))
      .subscribe((response) => this.stored.set(new Set(response.excludedNetworks)));
  }

  toggleNetwork(network: SocialNetworkName): void {
    const endpoint = ENDPOINT_SURFACE[this.surface];
    const write = this.excludedNetworks().has(network)
      ? this.api.include(endpoint, network)
      : this.api.exclude(endpoint, network);
    this.stored.update((current) => toggled(current, network));
    write.pipe(catchError(() => of(undefined))).subscribe(() => {
      this.load();
      this.subscriptions.reloadCountsQuietly();
      this.entries.reloadIfShowing(this.surface);
    });
  }
}

/** One store per surface with its own endpoints, built once for the app. */
export const NETWORK_EXCLUSION_STORES = new InjectionToken<
  Readonly<Record<NetworkExcludingSurface, NetworkExclusionsStore>>
>('NETWORK_EXCLUSION_STORES', {
  providedIn: 'root',
  factory: () => ({
    allItems: new NetworkExclusionsStore('allItems'),
    forYou: new NetworkExclusionsStore('forYou'),
    trending: new NetworkExclusionsStore('trending'),
  }),
});
