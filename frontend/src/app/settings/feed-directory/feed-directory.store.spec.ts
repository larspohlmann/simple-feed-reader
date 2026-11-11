import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Observable, Subject, of, throwError } from 'rxjs';
import { FeedDirectoryApi } from './feed-directory-api';
import { countByCountry, prepareRows } from './feed-directory-rows';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';
import { FeedDirectoryStore } from './feed-directory.store';
import {
  FeedDirectoryCountryFile,
  FeedDirectoryIndex,
  FeedDirectoryScope,
  FeedDirectorySubscribed,
} from './feed-directory.models';

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
const INDEX: FeedDirectoryIndex = { countries: [], languages: {} };
const SUBSCRIBED: FeedDirectorySubscribed = {
  urls: ['heise.de/rss/heise.rdf'],
  hosts: ['heise.de'],
};

describe('FeedDirectoryStore', () => {
  const scope = signal<FeedDirectoryScope>({ countries: ['DE', 'AT'], languages: ['de'] });
  let country: jest.Mock<Observable<FeedDirectoryCountryFile>, [string]>;
  let store: FeedDirectoryStore;

  function loadedStore(): FeedDirectoryStore {
    store.load();
    return store;
  }

  const hosts = (): string[] => store.rows().map((row) => row.host);

  beforeEach(() => {
    scope.set({ countries: ['DE', 'AT'], languages: ['de'] });
    country = jest.fn((code: string) => of(code === 'DE' ? DE : AT));
    TestBed.configureTestingModule({
      providers: [
        FeedDirectoryStore,
        { provide: FeedDirectoryScopeService, useValue: { scope } },
        {
          provide: FeedDirectoryApi,
          useValue: { index: () => of(INDEX), subscribed: () => of(SUBSCRIBED), country },
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
    expect(store.index()).toBe(INDEX);
    expect(hosts()).toEqual(['orf.at', 'heise.de', 'zeit.de', 'b.de']);
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

  it('counts per country ignoring the country filter', () => {
    loadedStore();
    store.country.set('AT');
    store.toggleCategory('Politics & Society');

    const expected = countByCountry(
      [
        ...prepareRows(DE, new Set(['de']), SUBSCRIBED),
        ...prepareRows(AT, new Set(['de']), SUBSCRIBED),
      ],
      {
        mode: 'channels',
        country: null,
        categories: new Set(['Politics & Society']),
        lengths: new Set(),
        languages: new Set(),
        query: '',
        sort: 'top',
      },
    );
    expect(store.countryCounts()).toEqual(expected);
    expect(store.countryCounts().get('DE')).toBe(2);
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

  it('fails when a country request fails, and retry loads again', () => {
    country.mockImplementation((code) =>
      code === 'AT' ? throwError(() => new Error('boom')) : of(DE),
    );
    store.load();
    expect(store.status()).toBe('failed');

    country.mockImplementation((code) => of(code === 'DE' ? DE : AT));
    store.retry();

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

  it('marks a feed subscribed and its row followed', () => {
    loadedStore();
    const before = store.rows().find((row) => row.host === 'zeit.de')!;
    expect(before.following).toBe(false);

    store.markSubscribed('https://www.zeit.de/politik/index');

    const after = store.rows().find((row) => row.host === 'zeit.de')!;
    expect(after.following).toBe(true);
    expect(after.feeds[0].subscribed).toBe(true);
    expect(after.feeds[1].subscribed).toBe(false);
  });
});
