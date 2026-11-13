import { Injectable, inject } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';
import { EXCLUSION_SURFACES, ExclusionSurface } from '../feed-exclusions';
import { NETWORK_EXCLUSION_STORES, NetworkSwitchStore } from './network-exclusions.store';
import { SavedSearchesStore } from './saved-searches.store';

/** Every surface's network switches, one store each. */
@Injectable({ providedIn: 'root' })
export class NetworkSurfaceSwitches {
  private readonly excludingStores = inject(NETWORK_EXCLUSION_STORES);
  private readonly stores: Readonly<Record<ExclusionSurface, NetworkSwitchStore>> = {
    ...this.excludingStores,
    savedSearches: inject(SavedSearchesStore),
  };

  excludedNetworks(surface: ExclusionSurface): ReadonlySet<SocialNetworkName> {
    return this.stores[surface].excludedNetworks();
  }

  isExcluded(network: SocialNetworkName, surface: ExclusionSurface): boolean {
    return this.excludedNetworks(surface).has(network);
  }

  toggle(network: SocialNetworkName, surface: ExclusionSurface): void {
    this.stores[surface].toggleNetwork(network);
  }

  /** In title order: the network marker names its surfaces in this order. */
  excludedSurfaces(network: SocialNetworkName): ExclusionSurface[] {
    return EXCLUSION_SURFACES.filter((surface) => this.isExcluded(network, surface));
  }

  /** Learns each surface's excluded networks; saved searches learn theirs with the searches. */
  loadExclusions(): void {
    Object.values(this.excludingStores).forEach((store) => store.load());
  }

  loadExclusionsIfStale(): void {
    Object.values(this.excludingStores).forEach((store) => store.loadIfStale());
  }
}
