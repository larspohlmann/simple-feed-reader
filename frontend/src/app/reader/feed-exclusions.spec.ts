import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import {
  SURFACE_DESCRIPTORS,
  exclusionTitle,
  ownExcludedSurfaces,
  flipFeedFlag,
  surfacesIn,
} from './feed-exclusions';
import { ManageActions } from './feeds/manage/manage-actions.service';
import { makeSubscription } from './testing/subscription.factory';

describe('ownExcludedSurfaces', () => {
  it('is empty for a plain feed with every switch on', () => {
    expect(ownExcludedSurfaces(makeSubscription())).toEqual([]);
  });

  it('lists every surface whose switch is off, in sidebar order', () => {
    const feed = makeSubscription({
      includeInAllItems: false,
      includeInForYou: false,
      includeInSavedSearches: false,
      includeInTrending: false,
    });

    expect(ownExcludedSurfaces(feed)).toEqual(['allItems', 'forYou', 'savedSearches', 'trending']);
  });

  it('ignores the network a social feed belongs to', () => {
    const feed = makeSubscription({
      social: { network: 'bluesky', kind: 'profile', accountId: null },
    });

    expect(ownExcludedSurfaces(feed)).toEqual([]);
  });
});

describe('exclusionTitle', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] }));

  it('names the surfaces the feed is hidden from', () => {
    const i18n = TestBed.inject(TranslocoService);

    expect(exclusionTitle(i18n, ['allItems', 'savedSearches', 'trending'])).toBe(
      'Hidden from All items, Saved searches, Trending',
    );
  });
});

describe('SURFACE_DESCRIPTORS', () => {
  it('lists All items, For you, saved searches and Trending, in menu order', () => {
    expect(SURFACE_DESCRIPTORS.map((descriptor) => descriptor.surface)).toEqual([
      'allItems',
      'forYou',
      'savedSearches',
      'trending',
    ]);
  });

  it('labels the Trending switch', () => {
    expect(SURFACE_DESCRIPTORS[3].includeKey).toBe('reader.includeInTrending');
  });

  it('flips each flag through ManageActions.setInclusion', () => {
    const feed = makeSubscription({
      includeInAllItems: true,
      includeInForYou: false,
      includeInSavedSearches: true,
      includeInTrending: false,
    });
    const actions = { setInclusion: jest.fn() };

    for (const { surface } of SURFACE_DESCRIPTORS) {
      flipFeedFlag(actions as unknown as ManageActions, surface, feed);
    }

    expect(actions.setInclusion.mock.calls).toEqual([
      [feed, 'allItems', false],
      [feed, 'forYou', true],
      [feed, 'savedSearches', false],
      [feed, 'trending', true],
    ]);
  });
});

describe('surfacesIn', () => {
  it('names the surfaces whose flag a change carries, in title order', () => {
    expect(surfacesIn({ includeInTrending: false, includeInAllItems: true })).toEqual([
      'allItems',
      'trending',
    ]);
  });
});
