import { Injectable, computed, inject } from '@angular/core';
import { SOCIAL_NETWORKS } from '../../core/social-network';
import { AllItemsNetworksStore } from './all-items-networks.store';
import { ForYouNetworksStore } from './for-you-networks.store';
import { SavedSearchesStore } from './saved-searches.store';
import { TagsStore } from './tags.store';
import { VisibilitySwitches } from './visibility';

/** The stored switches `isVisibleIn` reads, gathered live from their stores. */
@Injectable({ providedIn: 'root' })
export class VisibilitySwitchesStore {
  private readonly tags = inject(TagsStore);
  private readonly allItemsNetworks = inject(AllItemsNetworksStore);
  private readonly forYouNetworks = inject(ForYouNetworksStore);
  private readonly savedSearches = inject(SavedSearchesStore);

  readonly switches = computed<VisibilitySwitches>(() => {
    const savedSearchNetworks = this.savedSearches.includedNetworks();
    return {
      tags: this.tags.tags(),
      excludedNetworks: {
        allItems: this.allItemsNetworks.excludedNetworks(),
        forYou: this.forYouNetworks.excludedNetworks(),
        savedSearches: new Set(
          SOCIAL_NETWORKS.filter((network) => !savedSearchNetworks.has(network)),
        ),
      },
    };
  });
}
