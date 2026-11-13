import { Injectable, inject } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';
import { ExclusionSurface } from '../feed-exclusions';
import { AllItemsNetworksStore } from './all-items-networks.store';
import { ForYouNetworksStore } from './for-you-networks.store';
import { SavedSearchesStore } from './saved-searches.store';

export interface NetworkSurfaceSwitch {
  readonly surface: ExclusionSurface;
  readonly isExcluded: (network: SocialNetworkName) => boolean;
  readonly toggle: (network: SocialNetworkName) => void;
  readonly labelKey: string;
}

/** A network's three surface switches over their stores, whatever default each store keeps. */
@Injectable({ providedIn: 'root' })
export class NetworkSurfaceSwitches {
  private readonly allItemsNetworks = inject(AllItemsNetworksStore);
  private readonly forYouNetworks = inject(ForYouNetworksStore);
  private readonly savedSearches = inject(SavedSearchesStore);

  /** In title order: the network marker names its surfaces in this order. */
  readonly all: readonly NetworkSurfaceSwitch[] = [
    {
      surface: 'allItems',
      isExcluded: (network) => this.allItemsNetworks.excludedNetworks().has(network),
      toggle: (network) => this.allItemsNetworks.toggleNetwork(network),
      labelKey: 'reader.includeInAllItems',
    },
    {
      surface: 'forYou',
      isExcluded: (network) => this.forYouNetworks.excludedNetworks().has(network),
      toggle: (network) => this.forYouNetworks.toggleNetwork(network),
      labelKey: 'reader.includeInForYou',
    },
    {
      surface: 'savedSearches',
      isExcluded: (network) => !this.savedSearches.includedNetworks().has(network),
      toggle: (network) => this.savedSearches.toggleNetwork(network),
      labelKey: 'reader.includeInSavedSearches',
    },
  ];
}
