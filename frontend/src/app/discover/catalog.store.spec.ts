import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { API_BASE_URL } from '../core/api';
import { CatalogStore } from './catalog.store';

const WITH_FEEDS = {
  categories: [
    {
      id: 1,
      key: 'technology',
      name: 'Technology',
      icon: 'memory',
      color: '#3b82f6',
      feeds: [
        {
          id: 10,
          title: 'The Verge',
          description: null,
          siteUrl: null,
          faviconUrl: '/f/10',
          subscribed: false,
        },
      ],
    },
  ],
};

describe('CatalogStore', () => {
  let store: CatalogStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(CatalogStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('starts unresolved, which is not the same as empty', () => {
    expect(store.resolved()).toBe(false);
    expect(store.hasEntries()).toBe(false);
  });

  it('resolves with entries', () => {
    store.load();
    http.expectOne('https://api.test/api/catalog').flush(WITH_FEEDS);

    expect(store.resolved()).toBe(true);
    expect(store.hasEntries()).toBe(true);
  });

  it('treats a catalog of categories with no feeds as empty', () => {
    store.load();
    http.expectOne('https://api.test/api/catalog').flush({
      categories: [
        { id: 1, key: 'empty', name: 'Empty', icon: 'memory', color: '#3b82f6', feeds: [] },
      ],
    });

    expect(store.resolved()).toBe(true);
    expect(store.hasEntries()).toBe(false);
  });

  it('fetches once however many callers ask', () => {
    store.load();
    store.load();

    http.expectOne('https://api.test/api/catalog').flush(WITH_FEEDS);
    // A second expectOne would throw if the store had issued another request.
  });

  it('resolves as empty when the request fails, so nothing redirects into a broken picker', () => {
    store.load();
    http
      .expectOne('https://api.test/api/catalog')
      .flush('nope', { status: 500, statusText: 'Server Error' });

    expect(store.resolved()).toBe(true);
    expect(store.hasEntries()).toBe(false);
    expect(store.error()).not.toBeNull();
  });
});
