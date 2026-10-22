import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import {
  FEED_FLAG_TOGGLES,
  exclusionTitle,
  excludedSurfaces,
  feedFlagLabelKey,
  flipFeedFlag,
} from './feed-exclusions';
import { ManageActions } from './feeds/manage/manage-actions.service';
import { makeSubscription } from './testing/subscription.factory';

describe('excludedSurfaces', () => {
  it('is empty for a plain feed with every switch on', () => {
    expect(excludedSurfaces(makeSubscription(), new Set())).toEqual([]);
  });

  it('lists every surface whose switch is off, in sidebar order', () => {
    const feed = makeSubscription({
      includeInAllItems: false,
      includeInForYou: false,
      includeInSavedSearches: false,
    });

    expect(excludedSurfaces(feed, new Set())).toEqual(['allItems', 'forYou', 'savedSearches']);
  });

  it('counts a social feed of an unincluded network as out of saved searches', () => {
    const feed = makeSubscription({
      social: { network: 'bluesky', kind: 'profile', accountId: null },
    });

    expect(excludedSurfaces(feed, new Set())).toEqual(['savedSearches']);
    expect(excludedSurfaces(feed, new Set(['bluesky']))).toEqual([]);
  });
});

describe('exclusionTitle', () => {
  beforeEach(() => TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] }));

  it('names the surfaces the feed is hidden from', () => {
    const i18n = TestBed.inject(TranslocoService);

    expect(exclusionTitle(i18n, ['allItems', 'savedSearches'])).toBe(
      'Hidden from All items, Saved searches',
    );
  });
});

describe('FEED_FLAG_TOGGLES', () => {
  const manage = (): jest.Mocked<
    Pick<ManageActions, 'setIncludeInAllItems' | 'setIncludeInForYou' | 'setIncludeInSavedSearches'>
  > => ({
    setIncludeInAllItems: jest.fn(),
    setIncludeInForYou: jest.fn(),
    setIncludeInSavedSearches: jest.fn(),
  });

  it('lists All items, For you and saved searches, in menu order', () => {
    expect(FEED_FLAG_TOGGLES.map((toggle) => toggle.surface)).toEqual([
      'allItems',
      'forYou',
      'savedSearches',
    ]);
  });

  it('offers to exclude an included feed and to include an excluded one', () => {
    const [allItems, forYou, savedSearches] = FEED_FLAG_TOGGLES;
    const feed = makeSubscription({
      includeInAllItems: true,
      includeInForYou: false,
      includeInSavedSearches: true,
    });

    expect(feedFlagLabelKey(allItems, feed)).toBe('reader.excludeFromAllItems');
    expect(feedFlagLabelKey(forYou, feed)).toBe('reader.includeInForYou');
    expect(feedFlagLabelKey(savedSearches, feed)).toBe('reader.excludeFromSavedSearches');
  });

  it('flips each flag through its own ManageActions setter', () => {
    const [allItems, forYou, savedSearches] = FEED_FLAG_TOGGLES;
    const feed = makeSubscription({
      includeInAllItems: true,
      includeInForYou: false,
      includeInSavedSearches: true,
    });
    const actions = manage();

    flipFeedFlag(actions as unknown as ManageActions, allItems, feed);
    flipFeedFlag(actions as unknown as ManageActions, forYou, feed);
    flipFeedFlag(actions as unknown as ManageActions, savedSearches, feed);

    expect(actions.setIncludeInAllItems).toHaveBeenCalledWith(feed, false);
    expect(actions.setIncludeInForYou).toHaveBeenCalledWith(feed, true);
    expect(actions.setIncludeInSavedSearches).toHaveBeenCalledWith(feed, false);
  });
});
