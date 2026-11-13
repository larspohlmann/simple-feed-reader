import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SocialNetworkName } from '../../core/social-network';
import { TagDto } from '../models';
import { makeTag } from '../testing/tag.factory';
import { tagsStoreStub } from '../testing/tags-store.stub';
import { NETWORK_EXCLUSION_STORES } from './network-exclusions.store';
import { SavedSearchesStore } from './saved-searches.store';
import { TagsStore } from './tags.store';
import { VisibilitySwitchesStore } from './visibility-switches.store';

describe('VisibilitySwitchesStore', () => {
  const tags = signal<TagDto[]>([]);
  const allItemsExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
  const forYouExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
  const savedSearchesExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
  const trendingExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());

  function setup(): VisibilitySwitchesStore {
    TestBed.configureTestingModule({
      providers: [
        { provide: TagsStore, useValue: tagsStoreStub(tags) },
        {
          provide: NETWORK_EXCLUSION_STORES,
          useValue: {
            allItems: { excludedNetworks: allItemsExcluded },
            forYou: { excludedNetworks: forYouExcluded },
            trending: { excludedNetworks: trendingExcluded },
          },
        },
        { provide: SavedSearchesStore, useValue: { excludedNetworks: savedSearchesExcluded } },
      ],
    });
    return TestBed.inject(VisibilitySwitchesStore);
  }

  it('gathers the tags and each surface’s excluded networks', () => {
    tags.set([makeTag(1, 'Reading')]);
    allItemsExcluded.set(new Set(['bluesky']));
    forYouExcluded.set(new Set(['mastodon']));
    savedSearchesExcluded.set(new Set());
    trendingExcluded.set(new Set(['bluesky', 'mastodon']));

    const switches = setup().switches();

    expect([...switches.tagsById.values()].map((tag) => tag.name)).toEqual(['Reading']);
    expect([...switches.excludedNetworks.allItems]).toEqual(['bluesky']);
    expect([...switches.excludedNetworks.forYou]).toEqual(['mastodon']);
    expect(switches.excludedNetworks.savedSearches.size).toBe(0);
    expect([...switches.excludedNetworks.trending]).toEqual(['bluesky', 'mastodon']);
  });

  it('takes the saved-search exclusions from their store', () => {
    savedSearchesExcluded.set(new Set(['bluesky']));

    const switches = setup().switches();

    expect([...switches.excludedNetworks.savedSearches]).toEqual(['bluesky']);
  });
});
