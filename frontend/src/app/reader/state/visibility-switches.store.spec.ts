import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SocialNetworkName } from '../../core/social-network';
import { TagDto } from '../models';
import { makeTag } from '../testing/tag.factory';
import { AllItemsNetworksStore } from './all-items-networks.store';
import { ForYouNetworksStore } from './for-you-networks.store';
import { SavedSearchesStore } from './saved-searches.store';
import { TagsStore } from './tags.store';
import { TrendingNetworksStore } from './trending-networks.store';
import { VisibilitySwitchesStore } from './visibility-switches.store';

describe('VisibilitySwitchesStore', () => {
  const tags = signal<TagDto[]>([]);
  const allItemsExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
  const forYouExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
  const savedSearchesIncluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
  const trendingExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());

  function setup(): VisibilitySwitchesStore {
    TestBed.configureTestingModule({
      providers: [
        { provide: TagsStore, useValue: { tags } },
        { provide: AllItemsNetworksStore, useValue: { excludedNetworks: allItemsExcluded } },
        { provide: ForYouNetworksStore, useValue: { excludedNetworks: forYouExcluded } },
        { provide: SavedSearchesStore, useValue: { includedNetworks: savedSearchesIncluded } },
        { provide: TrendingNetworksStore, useValue: { excludedNetworks: trendingExcluded } },
      ],
    });
    return TestBed.inject(VisibilitySwitchesStore);
  }

  it('gathers the tags and each surface’s excluded networks', () => {
    tags.set([makeTag(1, 'Reading')]);
    allItemsExcluded.set(new Set(['bluesky']));
    forYouExcluded.set(new Set(['mastodon']));
    savedSearchesIncluded.set(new Set(['bluesky', 'mastodon']));
    trendingExcluded.set(new Set(['bluesky', 'mastodon']));

    const switches = setup().switches();

    expect(switches.tags.map((tag) => tag.name)).toEqual(['Reading']);
    expect([...switches.excludedNetworks.allItems]).toEqual(['bluesky']);
    expect([...switches.excludedNetworks.forYou]).toEqual(['mastodon']);
    expect(switches.excludedNetworks.savedSearches.size).toBe(0);
    expect([...switches.excludedNetworks.trending]).toEqual(['bluesky', 'mastodon']);
  });

  it('counts a network out of saved searches unless it is included', () => {
    savedSearchesIncluded.set(new Set(['mastodon']));

    const switches = setup().switches();

    expect([...switches.excludedNetworks.savedSearches]).toEqual(['bluesky']);
  });
});
