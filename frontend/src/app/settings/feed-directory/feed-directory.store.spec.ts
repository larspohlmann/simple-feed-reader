import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { manySitesFile } from '../../../testing/feed-directory-testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { FeedDirectoryApi } from './feed-directory-api';
import { FeedDirectoryNames } from './feed-directory-names';
import { countByCountry, prepareRows } from './feed-directory-rows';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectoryStore } from './feed-directory.store';
import { FeedDirectoryScope } from '../../core/preferences/feed-directory-scope';
import { FeedDirectoryCountryFile, FeedDirectoryIndex } from './feed-directory.models';
import { SubscriptionDto } from '../../reader/models';
import { Problem } from '../../core/problem';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';

const DE: FeedDirectoryCountryFile = {
  country: 'DE',
  categories: ['Politics & Society', 'Sports'],
  languages: ['de', 'en'],
  domains: [
    [
      'zeit.de',
      998,
      1770,
      [0],
      [
        [0, 0, ['https://www.zeit.de/politik/index']],
        [1, 1, ['https://zeit.de/podcast']],
      ],
      'Weekly newspaper.',
    ],
    ['example.de', null, 300, [1], [[1, 0, ['https://example.de/feed']]], 'English only.'],
    [
      'heise.de',
      776,
      1334,
      [0, 1],
      [[0, 0, ['https://www.heise.de/rss/heise.rdf', 'https://www.heise.de/security/rss']]],
      'IT news.',
    ],
    ['b.de', null, null, [0], [[1, 0, ['https://b.de/feed']]], null],
  ],
};
const AT: FeedDirectoryCountryFile = {
  country: 'AT',
  categories: ['News'],
  languages: ['de'],
  domains: [['orf.at', 500, 3000, [0], [[0, 0, ['https://orf.at/feed']]], 'Public broadcaster.']],
};
const INDEX: FeedDirectoryIndex = {
  countries: [
    { code: 'DE', name: 'Germany', sites: 4, languages: { de: 3, en: 2 } },
    { code: 'AT', name: 'Austria', sites: 1, languages: { de: 1 } },
    { code: 'QQ', name: 'Qland', sites: 0, languages: {} },
  ],
  languages: { de: 4, en: 2 },
};

describe('FeedDirectoryStore', () => {
  const scope = signal<FeedDirectoryScope>({ countries: ['DE', 'AT'], languages: ['de'] });
  const subscriptions = signal<Partial<SubscriptionDto>[]>([]);
  const resolved = signal(true);
  const error = signal<Problem | null>(null);
  const loadIfStale = jest.fn();
  let country: jest.Mock<Observable<FeedDirectoryCountryFile>, [string]>;
  let store: FeedDirectoryStore;

  function loadedStore(): FeedDirectoryStore {
    store.load();
    return store;
  }

  const hosts = (): string[] => store.rows().map((row) => row.host);

  beforeEach(() => {
    scope.set({ countries: ['DE', 'AT'], languages: ['de'] });
    subscriptions.set([{ id: 7, feedUrl: 'https://www.heise.de/rss/heise.rdf' }]);
    resolved.set(true);
    error.set(null);
    loadIfStale.mockReset();
    country = jest.fn((code: string) => of(code === 'DE' ? DE : AT));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        FeedDirectoryStore,
        { provide: FeedDirectoryScopeService, useValue: { scope } },
        { provide: SubscriptionsStore, useValue: { subscriptions, resolved, error, loadIfStale } },
        {
          provide: FeedDirectoryApi,
          useValue: { index: () => of(INDEX), country },
        },
      ],
    });
    store = TestBed.inject(FeedDirectoryStore);
  });

  it('starts loading', () => {
    expect(store.status()).toBe('loading');
  });

  it('requests one country file per scoped country and becomes ready', () => {
    loadedStore();

    expect(country.mock.calls.map(([code]) => code)).toEqual(['DE', 'AT']);
    expect(store.status()).toBe('ready');
    expect(hosts()).toEqual(['orf.at', 'heise.de', 'zeit.de', 'b.de']);
  });

  it('skips a scoped country the index does not list', () => {
    scope.set({ countries: ['XX', 'AT'], languages: ['de'] });

    loadedStore();

    expect(country.mock.calls.map(([code]) => code)).toEqual(['AT']);
    expect(store.status()).toBe('ready');
    expect(hosts()).toEqual(['orf.at']);
  });

  it('becomes ready with no rows when the index lists none of the scoped countries', () => {
    scope.set({ countries: ['XX'], languages: ['de'] });

    loadedStore();

    expect(country).not.toHaveBeenCalled();
    expect(store.status()).toBe('ready');
    expect(store.rows()).toEqual([]);
  });

  it('names a country Intl cannot name after the index', () => {
    const names = TestBed.inject(FeedDirectoryNames);
    expect(names.country('QQ')).toBe('QQ');

    loadedStore();

    expect(names.country('QQ')).toBe('Qland');
  });

  it('requests the country files in parallel', () => {
    const de = new Subject<FeedDirectoryCountryFile>();
    const at = new Subject<FeedDirectoryCountryFile>();
    country.mockImplementation((code) => (code === 'DE' ? de : at));

    store.load();

    expect(country).toHaveBeenCalledTimes(2);
    expect(store.status()).toBe('loading');
    de.next(DE);
    de.complete();
    at.next(AT);
    at.complete();
    expect(store.status()).toBe('ready');
  });

  it('becomes ready with no rows when the scope has no countries', () => {
    scope.set({ countries: [], languages: [] });

    loadedStore();

    expect(country).not.toHaveBeenCalled();
    expect(store.status()).toBe('ready');
    expect(store.rows()).toEqual([]);
  });

  it('filters rows by mode', () => {
    loadedStore();
    store.mode.set('podcasts');

    expect(hosts()).toEqual(['zeit.de']);
  });

  it('filters rows by country', () => {
    loadedStore();
    store.country.set('AT');

    expect(hosts()).toEqual(['orf.at']);
  });

  it('filters rows by category', () => {
    loadedStore();
    store.toggleCategory('Sports');

    expect(hosts()).toEqual(['b.de']);
  });

  it('filters rows by length', () => {
    loadedStore();
    store.toggleLength('long');

    expect(hosts()).toEqual(['orf.at']);
  });

  it('filters rows by language', () => {
    scope.set({ countries: ['DE'], languages: ['de', 'en'] });
    loadedStore();
    store.toggleLanguage('en');

    expect(hosts()).toEqual(['heise.de', 'example.de']);
  });

  it('filters rows by query', () => {
    loadedStore();
    store.query.set('broadcaster');

    expect(hosts()).toEqual(['orf.at']);
  });

  it('sorts rows', () => {
    loadedStore();
    store.sort.set('az');

    expect(hosts()).toEqual(['b.de', 'heise.de', 'orf.at', 'zeit.de']);
  });

  it('toggles a filter value on and off', () => {
    store.toggleCategory('Sports');
    expect([...store.categories()]).toEqual(['Sports']);

    store.toggleCategory('Sports');
    expect([...store.categories()]).toEqual([]);
  });

  it('narrows the category filter to the one category a pill names', () => {
    loadedStore();
    store.toggleCategory('Sports');
    store.focusCategory('Politics & Society');

    expect([...store.categories()]).toEqual(['Politics & Society']);
    expect(store.onlyCategory()).toBe('Politics & Society');
  });

  it('clears the category filter when the pill of the only chosen category is clicked again', () => {
    loadedStore();
    store.focusCategory('Sports');
    store.focusCategory('Sports');

    expect(store.categories().size).toBe(0);
    expect(store.onlyCategory()).toBeNull();
  });

  it('leaves the other filters alone when a pill narrows the category', () => {
    loadedStore();
    store.query.set('heise');
    store.sort.set('az');
    store.focusCategory('Sports');

    expect(store.query()).toBe('heise');
    expect(store.sort()).toBe('az');
    expect(store.mode()).toBe('channels');
  });

  it('has no single category while two are chosen', () => {
    loadedStore();
    store.toggleCategory('Sports');
    store.toggleCategory('Politics & Society');

    expect(store.onlyCategory()).toBeNull();
  });

  it('counts per country ignoring the country filter', () => {
    loadedStore();
    store.country.set('AT');
    store.toggleCategory('Politics & Society');

    const expected = countByCountry(
      [...prepareRows(DE, new Set(['de'])), ...prepareRows(AT, new Set(['de']))],
      {
        mode: 'channels',
        categories: new Set(['Politics & Society']),
        lengths: new Set(),
        languages: new Set(),
        query: '',
      },
    );
    expect(store.countryCounts()).toEqual(expected);
    expect(store.countryCounts().get('DE')).toBe(2);
  });

  it('keeps the country counts when only the country or the sort changes', () => {
    loadedStore();
    const counts = store.countryCounts();

    store.country.set('AT');
    store.sort.set('az');

    expect(store.countryCounts()).toBe(counts);
  });

  it('keeps the picked categories as they are when the new mode drops none of them', () => {
    loadedStore();
    store.toggleCategory('Sports');
    const picked = store.categories();

    store.mode.set('podcasts');

    expect(store.categories()).toBe(picked);
  });

  it('totals the rows in scope for the current mode, whatever the filters', () => {
    loadedStore();
    store.query.set('nothing matches this');

    expect(store.total()).toBe(4);
    store.mode.set('podcasts');
    expect(store.total()).toBe(1);
  });

  it('lists the categories present in the current mode', () => {
    loadedStore();

    expect(store.categoryOptions()).toEqual(['News', 'Politics & Society', 'Sports']);
    store.mode.set('podcasts');
    expect(store.categoryOptions()).toEqual(['Sports']);
  });

  it('drops picked categories the new mode does not offer', () => {
    loadedStore();
    store.toggleCategory('Politics & Society');
    store.toggleCategory('Sports');

    store.mode.set('podcasts');

    expect([...store.categories()]).toEqual(['Sports']);
    expect(hosts()).toEqual(['zeit.de']);
  });

  it('fails when a country request fails, and the next load succeeds', () => {
    country.mockImplementation((code) =>
      code === 'AT' ? throwError(() => new Error('boom')) : of(DE),
    );
    store.load();
    expect(store.status()).toBe('failed');

    country.mockImplementation((code) => of(code === 'DE' ? DE : AT));
    store.load();

    expect(store.status()).toBe('ready');
    expect(hosts()).toContain('orf.at');
  });

  it('drops an earlier load when a new one starts, so its late result never lands', () => {
    const firstDe = new Subject<FeedDirectoryCountryFile>();
    country.mockImplementationOnce(() => firstDe).mockImplementationOnce(() => of(AT));
    store.load();

    scope.set({ countries: ['AT'], languages: ['de'] });
    store.load();
    expect(hosts()).toEqual(['orf.at']);

    firstDe.next(DE);
    firstDe.complete();

    expect(store.status()).toBe('ready');
    expect(hosts()).toEqual(['orf.at']);
    expect(firstDe.observed).toBe(false);
  });

  it('keeps the country and language filters only where the scope still holds them', () => {
    scope.set({ countries: ['DE', 'AT'], languages: ['de', 'en'] });
    store.country.set('DE');
    store.toggleLanguage('en');
    store.toggleLanguage('de');

    scope.set({ countries: ['AT'], languages: ['de'] });
    store.load();

    expect(store.country()).toBeNull();
    expect([...store.languages()]).toEqual(['de']);
  });

  it('keeps a country filter the scope still holds', () => {
    store.country.set('AT');

    store.load();

    expect(store.country()).toBe('AT');
  });

  it('marks the feeds the subscriptions store holds', () => {
    loadedStore();
    const heise = store.rows().find((row) => row.host === 'heise.de')!;
    expect(heise.feeds.map((feed) => feed.subscribed)).toEqual([true, false]);
    expect(heise.following).toBe(true);
  });

  it('flips a row back when its subscription goes away', () => {
    loadedStore();
    subscriptions.set([]);
    const heise = store.rows().find((row) => row.host === 'heise.de')!;
    expect(heise.feeds.some((feed) => feed.subscribed)).toBe(false);
    expect(heise.following).toBe(false);
  });

  it('keeps its rows when the subscriptions change but their addresses do not', () => {
    loadedStore();
    const before = store.rows();
    subscriptions.set(subscriptions().map((subscription) => ({ ...subscription })));
    expect(store.rows()).toBe(before);
  });

  it('asks the subscriptions store to load when it is stale', () => {
    loadedStore();
    expect(loadIfStale).toHaveBeenCalled();
  });

  it('stays loading until the subscriptions store has answered', () => {
    resolved.set(false);
    loadedStore();
    expect(store.status()).toBe('loading');
    resolved.set(true);
    expect(store.status()).toBe('ready');
  });

  it('fails when the subscriptions could not load and there are none to show', () => {
    subscriptions.set([]);
    error.set({ title: 'Boom', status: 500 } as Problem);
    loadedStore();
    expect(store.status()).toBe('failed');
  });

  it('stays ready when a later refresh of the subscriptions fails but some are known', () => {
    error.set({ title: 'Boom', status: 500 } as Problem);
    loadedStore();
    expect(store.status()).toBe('ready');
  });

  it('finds the subscription behind a directory feed by its normalised address', () => {
    expect(store.subscriptionFor('http://heise.de/rss/heise.rdf/')?.id).toBe(7);
    expect(store.subscriptionFor('https://heise.de/security/rss')).toBeNull();
  });

  describe('batches', () => {
    beforeEach(() => {
      scope.set({ countries: ['DE'], languages: ['de'] });
      country.mockReturnValue(of(manySitesFile(250)));
      loadedStore();
    });

    it('shows the first batch and adds one on demand', () => {
      expect(store.shownRows().length).toBe(100);
      expect(store.hasMore()).toBe(true);

      store.showMore();
      expect(store.shownRows().length).toBe(200);

      store.showMore();
      expect(store.shownRows().length).toBe(250);
      expect(store.hasMore()).toBe(false);
    });

    it('starts over at the first batch when a filter or the sort changes', () => {
      store.showMore();
      store.sort.set('az');
      expect(store.shownRows().length).toBe(100);

      store.showMore();
      store.query.set('site2');
      expect(store.shownRows().length).toBe(61);
      expect(store.hasMore()).toBe(false);
    });

    it('keeps the batches shown when only the subscriptions change', () => {
      store.showMore();

      subscriptions.set([{ id: 9, feedUrl: 'https://site150.de/feed' }]);

      expect(store.shownRows().length).toBe(200);
      expect(store.shownRows().find((row) => row.host === 'site150.de')!.feeds[0].subscribed).toBe(
        true,
      );
    });

    it('starts over at the first batch when the directory loads again', () => {
      store.showMore();

      store.load();

      expect(store.shownRows().length).toBe(100);
    });
  });
});
