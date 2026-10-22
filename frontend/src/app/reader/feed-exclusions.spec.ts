import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { exclusionTitle, excludedSurfaces } from './feed-exclusions';
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
