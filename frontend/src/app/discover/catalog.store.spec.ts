import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { API_BASE_URL } from '../core/api';
import { TokenStore } from '../core/token.store';
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

/** The same catalog as seen by a user who is already subscribed to The Verge. */
const SUBSCRIBED = {
  categories: [{ ...WITH_FEEDS.categories[0], feeds: [{ ...WITH_FEEDS.categories[0].feeds[0] }] }],
};
SUBSCRIBED.categories[0].feeds[0].subscribed = true;

describe('CatalogStore', () => {
  let store: CatalogStore;
  let http: HttpTestingController;
  let tokens: TokenStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    tokens = TestBed.inject(TokenStore);
    tokens.set('user-a.jwt');
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

  // #263. The `subscribed` flag is per-user, and this store outlives a logout:
  // nothing reloads the page, so the root injector survives into the next
  // user's session and the resolved() guard would serve them the previous
  // user's subscription state.
  describe('when the signed-in identity changes', () => {
    it('drops the cached catalog', () => {
      store.load();
      http.expectOne('https://api.test/api/catalog').flush(SUBSCRIBED);
      expect(store.resolved()).toBe(true);

      tokens.clear();
      TestBed.tick();

      expect(store.resolved()).toBe(false);
      expect(store.categories()).toEqual([]);
    });

    it('refetches, so the next user sees their own subscription state', () => {
      store.load();
      http.expectOne('https://api.test/api/catalog').flush(SUBSCRIBED);
      expect(store.categories()[0].feeds[0].subscribed).toBe(true);

      tokens.clear();
      tokens.set('user-b.jwt');
      TestBed.tick();

      store.load();
      http.expectOne('https://api.test/api/catalog').flush(WITH_FEEDS);
      expect(store.categories()[0].feeds[0].subscribed).toBe(false);
    });

    // The 401 path in auth.interceptor.ts clears the token mid-flight, so a
    // response issued for the previous user can still be on the wire.
    it('abandons a request the previous user issued', () => {
      store.load();
      const stale = http.expectOne('https://api.test/api/catalog');

      tokens.clear();
      TestBed.tick();

      // Cancelled, so its response can never resolve the store — and the guard
      // is off, so the next user's load() issues a request of their own.
      expect(stale.cancelled).toBe(true);
      expect(store.loading()).toBe(false);
      expect(store.resolved()).toBe(false);
    });
  });

  // A reload rebuilds every service against the same stored token. Treating
  // that as a new identity would throw away a catalog the reload should keep.
  it('does not treat the token it started with as a change', () => {
    store.load();
    http.expectOne('https://api.test/api/catalog').flush(WITH_FEEDS);

    TestBed.tick();

    expect(store.resolved()).toBe(true);
    expect(store.hasEntries()).toBe(true);
  });
});
