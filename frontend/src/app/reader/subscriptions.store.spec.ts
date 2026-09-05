import { effect } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { TokenStore } from '../core/token.store';
import { ReaderApi } from './reader-api';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { SubscriptionsStore, buildTagTree, sumUnread, untaggedSubs } from './subscriptions.store';
import { SubscriptionDto } from './models';

const tag = (id: number, name: string) => ({ id, name, color: null, icon: null, position: 0 });
const sub = (
  id: number,
  unread: number,
  tags = [] as ReturnType<typeof tag>[],
): SubscriptionDto => ({
  id,
  feedId: id * 10,
  title: `s${id}`,
  faviconUrl: null,
  customTitle: null,
  feedUrl: `https://f/${id}`,
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  lastFetchedAt: null,
  lastSuccessfulFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags,
  unreadCount: unread,
  includeInAllItems: true,
  includeInForYou: true,
});

describe('subscription derivations', () => {
  const subs = [
    sub(1, 3, [tag(10, 'News'), tag(20, 'Tech')]),
    sub(2, 6, [tag(20, 'Tech')]),
    sub(3, 0, []),
  ];
  it('sums per-tag unread with overlap', () => {
    const tree = buildTagTree(subs);
    expect(tree.map((n) => [n.tag.name, n.unreadCount])).toEqual([
      ['News', 3],
      ['Tech', 9],
    ]);
    expect(tree.find((n) => n.tag.name === 'Tech')!.subscriptions.map((s) => s.id)).toEqual([1, 2]);
  });
  it('lists untagged subs and totals each sub once', () => {
    expect(untaggedSubs(subs).map((s) => s.id)).toEqual([3]);
    expect(sumUnread(subs)).toBe(9);
  });

  it('orders untagged feeds by their position', () => {
    const at = (id: number, position: number): SubscriptionDto => ({ ...sub(id, 0), position });
    expect(untaggedSubs([at(1, 2), at(2, 0), at(3, 1)]).map((s) => s.id)).toEqual([2, 3, 1]);
  });

  it('excludes includeInAllItems=false feeds from the All items badge', () => {
    const excludedSubs = [sub(1, 5), { ...sub(2, 8), includeInAllItems: false }];
    expect(sumUnread(excludedSubs)).toBe(5);
  });

  it('still counts an excluded feed under its tag', () => {
    const excluded = { ...sub(2, 8, [tag(3, 'Tech')]), includeInAllItems: false };
    const tree = buildTagTree([excluded]);
    expect(tree[0].unreadCount).toBe(8);
  });
});

describe('buildTagTree with an explicit tag order', () => {
  const orderedTags = [
    { id: 20, name: 'Tech', color: null, icon: null, position: 0 },
    { id: 10, name: 'News', color: null, icon: null, position: 1 },
    { id: 30, name: 'Empty', color: null, icon: null, position: 2 },
  ];
  // A sub carrying one tag, with an explicit per-tag (feed) position.
  const inTag = (id: number, tagId: number, feedPos: number): SubscriptionDto =>
    sub(id, 1, [{ id: tagId, name: 'x', color: null, icon: null, position: feedPos }]);

  it('orders nodes by tag.position, includes empty tags, and orders feeds per-tag', () => {
    const subs = [inTag(1, 20, 1), inTag(2, 20, 0), inTag(3, 10, 0)];
    const tree = buildTagTree(subs, orderedTags);
    // Nodes follow the tag order, and the empty tag still appears.
    expect(tree.map((n) => n.tag.name)).toEqual(['Tech', 'News', 'Empty']);
    // Feeds within Tech follow their per-tag position: sub 2 (0) before sub 1 (1).
    expect(tree[0].subscriptions.map((s) => s.id)).toEqual([2, 1]);
    expect(tree[2].subscriptions).toEqual([]);
  });
});

describe('SubscriptionsStore', () => {
  let store: SubscriptionsStore;
  let ctrl: HttpTestingController;
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
    store = TestBed.inject(SubscriptionsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('loads and exposes derived signals', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3, [tag(20, 'Tech')]), sub(2, 6, [tag(20, 'Tech')])],
      favoritesCount: 4,
      keptCount: 2,
    });
    expect(store.totalUnread()).toBe(9);
    expect(store.tagTree()[0].unreadCount).toBe(9);
    expect(store.favoritesCount()).toBe(4);
    expect(store.keptCount()).toBe(2);
    expect(store.loading()).toBe(false);
  });

  it('cancels an older subscription request when a newer load starts', () => {
    store.load();
    const olderRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    store.load();
    const newerRequest = ctrl.expectOne('https://api.test/api/subscriptions');

    newerRequest.flush({ subscriptions: [sub(1, 5)], favoritesCount: 0, keptCount: 0 });

    expect(olderRequest.cancelled).toBe(true);
    expect(store.totalUnread()).toBe(5);
  });

  it('cancels every older request before an identity change can expose it', () => {
    store.load();
    store.load();
    const [olderRequest, newerRequest] = ctrl.match('https://api.test/api/subscriptions');

    newerRequest.flush({ subscriptions: [sub(1, 5)], favoritesCount: 0, keptCount: 0 });
    tokens.clear();
    TestBed.tick();

    expect(olderRequest.cancelled).toBe(true);
  });

  it('makes a previous empty result unresolved while the current account reloads', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [],
      favoritesCount: 0,
      keptCount: 0,
      viewedCount: 0,
    });
    expect(store.resolved()).toBe(true);

    store.load();

    expect(store.resolved()).toBe(false);
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3)],
      favoritesCount: 0,
      keptCount: 0,
      viewedCount: 0,
    });
    expect(store.resolved()).toBe(true);
  });

  describe('when the signed-in identity changes', () => {
    it('drops the completed subscription state from the previous user', () => {
      store.load();
      ctrl.expectOne('https://api.test/api/subscriptions').flush({
        subscriptions: [sub(1, 3)],
        favoritesCount: 2,
        keptCount: 1,
        viewedCount: 4,
      });

      tokens.clear();
      TestBed.tick();

      expect(store.subscriptions()).toEqual([]);
      expect(store.favoritesCount()).toBe(0);
      expect(store.keptCount()).toBe(0);
      expect(store.viewedCount()).toBe(0);
      expect(store.resolved()).toBe(false);
    });

    it('abandons a subscription request from the previous user', () => {
      store.load();
      const stale = ctrl.expectOne('https://api.test/api/subscriptions');

      tokens.clear();
      TestBed.tick();

      expect(stale.cancelled).toBe(true);
      expect(store.loading()).toBe(false);
      expect(store.resolved()).toBe(false);
    });

    it('loads the next user even when the previous result is still fresh', () => {
      jest.useFakeTimers({ now: new Date('2026-08-27T16:00:00Z') });
      store.loadIfStale();
      ctrl.expectOne('https://api.test/api/subscriptions').flush({
        subscriptions: [],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
      });

      tokens.clear();
      tokens.set('user-b.jwt');
      TestBed.tick();
      store.loadIfStale();

      ctrl.expectOne('https://api.test/api/subscriptions').flush({
        subscriptions: [sub(2, 1)],
        favoritesCount: 0,
        keptCount: 0,
        viewedCount: 0,
      });
      expect(store.subscriptions().map((subscription) => subscription.id)).toEqual([2]);
      jest.useRealTimers();
    });
  });

  it('loads sidebar counts at most once per freshness window', () => {
    jest.useFakeTimers({ now: new Date('2026-08-27T16:00:00Z') });

    store.loadIfStale();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 2)],
      favoritesCount: 0,
      keptCount: 0,
    });
    store.loadIfStale();
    ctrl.expectNone('https://api.test/api/subscriptions');

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS - 1);
    store.loadIfStale();
    ctrl.expectNone('https://api.test/api/subscriptions');

    jest.advanceTimersByTime(1);
    store.loadIfStale();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3)],
      favoritesCount: 0,
      keptCount: 0,
    });

    jest.useRealTimers();
  });

  it('optimistically bumps favourite/kept totals, clamped at zero', () => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscriptions: [sub(1, 3)], favoritesCount: 1, keptCount: 0 });
    store.bumpFavorites(1);
    expect(store.favoritesCount()).toBe(2);
    store.bumpFavorites(-5);
    expect(store.favoritesCount()).toBe(0); // never negative
    store.bumpKept(1);
    expect(store.keptCount()).toBe(1);
  });

  it('optimistically decrements and zeroes unread', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3, [tag(20, 'Tech')]), sub(2, 6)],
      favoritesCount: 0,
      keptCount: 0,
    });
    store.decrementUnread(1);
    expect(store.subscriptions().find((s) => s.id === 1)!.unreadCount).toBe(2);
    store.decrementUnread(1, 99);
    expect(store.subscriptions().find((s) => s.id === 1)!.unreadCount).toBe(0);
    store.zeroUnread({ subscription: 2 });
    expect(store.subscriptions().find((s) => s.id === 2)!.unreadCount).toBe(0);
  });

  it('optimistically patches the exclusion flags in place', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3), sub(2, 6)],
      favoritesCount: 0,
      keptCount: 0,
    });
    store.patchLocal(1, { includeInAllItems: false });
    expect(store.subscriptions().find((s) => s.id === 1)!.includeInAllItems).toBe(false);
    expect(store.subscriptions().find((s) => s.id === 1)!.includeInForYou).toBe(true);
    expect(store.subscriptions().find((s) => s.id === 2)!.includeInAllItems).toBe(true);
    store.patchLocal(1, { includeInForYou: false });
    expect(store.subscriptions().find((s) => s.id === 1)!.includeInForYou).toBe(false);
  });

  it('exposes unhealthy feeds and their count', () => {
    store.subscriptions.set([
      {
        ...sub(1, 0),
        title: 'Bravo',
        status: 'erroring',
        lastSuccessfulFetchAt: '2020-01-01T00:00:00Z',
      },
      { ...sub(2, 0), title: 'Alpha', status: 'gone' },
      { ...sub(3, 0), status: 'active' },
    ]);
    expect(store.unhealthy().map((s) => s.id)).toEqual([2, 1]);
    expect(store.unhealthyCount()).toBe(2);
  });

  it('captures a problem on error', () => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(store.error()?.status).toBe(500);
    expect(store.loading()).toBe(false);
  });
});

describe('SubscriptionsStore quiet reload', () => {
  let store: SubscriptionsStore;
  let ctrl: HttpTestingController;

  const counts = (subscriptions: SubscriptionDto[], favorites = 0, kept = 0, viewed = 0) => ({
    subscriptions,
    favoritesCount: favorites,
    keptCount: kept,
    viewedCount: viewed,
  });

  /** A settled store, one poll interval old, so a quiet reload may fire. */
  const settleAndAge = (subscriptions: SubscriptionDto[]) => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(counts(subscriptions, 1, 2, 3));
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
  };

  beforeEach(() => {
    localStorage.clear();
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    TestBed.inject(TokenStore).set('user-a.jwt');
    store = TestBed.inject(SubscriptionsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    ctrl.verify();
    jest.useRealTimers();
  });

  it('refreshes every count without ever raising the loading flag', () => {
    settleAndAge([sub(1, 2)]);

    store.reloadQuietlyIfStale();
    expect(store.loading()).toBe(false);

    ctrl.expectOne('https://api.test/api/subscriptions').flush(counts([sub(1, 7)], 4, 5, 6));
    expect(store.totalUnread()).toBe(7);
    expect(store.favoritesCount()).toBe(4);
    expect(store.keptCount()).toBe(5);
    expect(store.viewedCount()).toBe(6);
    expect(store.loading()).toBe(false);
  });

  it('stands aside until a real load has resolved the store', () => {
    // Boot order: the shell injects the poll before its own first load runs. A
    // tick that fetched here would stamp the freshness clock, silence that
    // load for a whole window, and leave `resolved` false — so the onboarding
    // redirect would never get to decide anything.
    store.reloadQuietlyIfStale();
    ctrl.expectNone('https://api.test/api/subscriptions');
    expect(store.resolved()).toBe(false);

    store.loadIfStale();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(counts([sub(1, 2)]));
    expect(store.resolved()).toBe(true);
  });

  it('waits out a tick already on the wire instead of stacking on it', () => {
    settleAndAge([sub(1, 1)]);
    store.reloadQuietlyIfStale();
    const slowTick = ctrl.expectOne('https://api.test/api/subscriptions');
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

    store.reloadQuietlyIfStale();

    ctrl.expectNone('https://api.test/api/subscriptions');
    expect(slowTick.cancelled).toBe(false);
    slowTick.flush(counts([sub(1, 4)]));
    expect(store.totalUnread()).toBe(4);
  });

  it('leaves a load in flight alone, so its resolved state still lands', () => {
    settleAndAge([sub(1, 1)]);
    store.load();
    const pendingLoad = ctrl.expectOne('https://api.test/api/subscriptions');
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

    store.reloadQuietlyIfStale();
    ctrl.expectNone('https://api.test/api/subscriptions');
    expect(pendingLoad.cancelled).toBe(false);

    pendingLoad.flush(counts([sub(1, 4)]));
    expect(store.totalUnread()).toBe(4);
    expect(store.resolved()).toBe(true);
  });

  it('drops a response the user has overtaken by reading an entry', () => {
    settleAndAge([sub(1, 5)]);
    store.reloadQuietlyIfStale();
    const tick = ctrl.expectOne('https://api.test/api/subscriptions');

    // The user reads one entry while the tick is on the wire. The response was
    // counted before that, so adopting it would put the badge back up to 5.
    store.decrementUnread(1);
    tick.flush(counts([sub(1, 5)], 9, 9, 9));

    expect(store.subscriptions()[0].unreadCount).toBe(4);
    expect(store.favoritesCount()).toBe(1);
  });

  it('adopts the server counts again on the tick after a local change', () => {
    settleAndAge([sub(1, 5)]);
    store.reloadQuietlyIfStale();
    const overtaken = ctrl.expectOne('https://api.test/api/subscriptions');
    store.decrementUnread(1);
    overtaken.flush(counts([sub(1, 5)]));

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    store.reloadQuietlyIfStale();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(counts([sub(1, 2)], 7, 8, 9));

    expect(store.subscriptions()[0].unreadCount).toBe(2);
    expect(store.favoritesCount()).toBe(7);
  });

  it('never takes resolved back, so a tick cannot re-fire the onboarding redirect', () => {
    settleAndAge([]);
    const resolvedSeen: boolean[] = [];
    TestBed.runInInjectionContext(() => effect(() => resolvedSeen.push(store.resolved())));
    TestBed.tick();

    store.reloadQuietlyIfStale();
    ctrl.expectOne('https://api.test/api/subscriptions').flush(counts([]));
    TestBed.tick();

    expect(resolvedSeen).toEqual([true]);
  });

  it('swallows a failed tick instead of raising the error banner', () => {
    settleAndAge([sub(1, 5)]);

    store.reloadQuietlyIfStale();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });

    expect(store.error()).toBeNull();
    expect(store.totalUnread()).toBe(5);
  });

  it('abandons a tick from the previous user on logout', () => {
    settleAndAge([sub(1, 5)]);
    store.reloadQuietlyIfStale();
    const tick = ctrl.expectOne('https://api.test/api/subscriptions');

    TestBed.inject(TokenStore).clear();
    TestBed.tick();

    // Cancelled outright, so the previous user's counts can never land in the
    // next user's sidebar — the response is off the wire, not merely ignored.
    expect(tick.cancelled).toBe(true);
    expect(store.subscriptions()).toEqual([]);
    expect(store.favoritesCount()).toBe(0);
  });
});

describe('SubscriptionsStore against an API that answers synchronously', () => {
  // An interceptor or a cache can answer without ever leaving the process. The
  // subscription is then already closed by the time it reaches the in-flight
  // slot, and parking it there would stop the poll for the rest of the session.
  const respond = (unread: number) => ({
    subscriptions: [sub(1, unread)],
    favoritesCount: 0,
    keptCount: 0,
    viewedCount: 0,
  });

  it('keeps ticking after a response that never left the process', () => {
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    let unread = 1;
    const subscriptions = jest.fn(() => of(respond(++unread)));
    TestBed.configureTestingModule({
      providers: [SubscriptionsStore, { provide: ReaderApi, useValue: { subscriptions } }],
    });
    const store = TestBed.inject(SubscriptionsStore);

    store.load();
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    store.reloadQuietlyIfStale();
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    store.reloadQuietlyIfStale();

    expect(subscriptions).toHaveBeenCalledTimes(3);
    expect(store.totalUnread()).toBe(4);
    jest.useRealTimers();
  });
});

describe('SubscriptionsStore counts-only reload', () => {
  let store: SubscriptionsStore;
  let ctrl: HttpTestingController;

  const list = 'https://api.test/api/subscriptions';
  const countsUrl = 'https://api.test/api/subscriptions/counts';

  const fullCounts = (subscriptions: SubscriptionDto[], favorites = 0, kept = 0, viewed = 0) => ({
    subscriptions,
    favoritesCount: favorites,
    keptCount: kept,
    viewedCount: viewed,
  });

  const countsBody = (
    subscriptions: { id: number; unreadCount: number }[],
    favorites = 0,
    kept = 0,
    viewed = 0,
  ) => ({ subscriptions, favoritesCount: favorites, keptCount: kept, viewedCount: viewed });

  /** A settled store, one interval old, so a counts tick may fire. */
  const settleAndAge = (subscriptions: SubscriptionDto[]) => {
    store.load();
    ctrl.expectOne(list).flush(fullCounts(subscriptions, 1, 2, 3));
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
  };

  beforeEach(() => {
    localStorage.clear();
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    TestBed.inject(TokenStore).set('user-a.jwt');
    store = TestBed.inject(SubscriptionsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    ctrl.verify();
    jest.useRealTimers();
  });

  it('fetches the cheap counts endpoint, not the full list', () => {
    settleAndAge([sub(1, 2)]);

    store.reloadCountsIfStale();

    ctrl.expectNone(list);
    ctrl.expectOne(countsUrl).flush(countsBody([{ id: 1, unreadCount: 7 }], 4, 5, 6));
    expect(store.totalUnread()).toBe(7);
    expect(store.favoritesCount()).toBe(4);
    expect(store.keptCount()).toBe(5);
    expect(store.viewedCount()).toBe(6);
    expect(store.loading()).toBe(false);
  });

  it('patches unread into the feed rows it already holds', () => {
    settleAndAge([sub(1, 2), sub(2, 9)]);

    store.reloadCountsIfStale();
    ctrl.expectOne(countsUrl).flush(countsBody([{ id: 2, unreadCount: 3 }]));

    // Feed 1 is absent from the payload, so it has no unread entries.
    expect(store.subscriptions().find((s) => s.id === 1)?.unreadCount).toBe(0);
    expect(store.subscriptions().find((s) => s.id === 2)?.unreadCount).toBe(3);
  });

  it('keeps the same array identity when no count moved', () => {
    settleAndAge([sub(1, 2)]);
    const before = store.subscriptions();

    store.reloadCountsIfStale();
    ctrl.expectOne(countsUrl).flush(countsBody([{ id: 1, unreadCount: 2 }], 1, 2, 3));

    // Nothing moved, so the array is not replaced and the derived signals do
    // not recompute (#720).
    expect(store.subscriptions()).toBe(before);
  });

  it('drops a response the user has overtaken by reading an entry', () => {
    settleAndAge([sub(1, 5)]);
    store.reloadCountsIfStale();
    const tick = ctrl.expectOne(countsUrl);

    store.decrementUnread(1);
    tick.flush(countsBody([{ id: 1, unreadCount: 5 }], 9, 9, 9));

    expect(store.subscriptions()[0].unreadCount).toBe(4);
    expect(store.favoritesCount()).toBe(1);
  });

  it('stands aside until a real load has resolved the store', () => {
    store.reloadCountsIfStale();
    ctrl.expectNone(countsUrl);
    expect(store.resolved()).toBe(false);
  });

  it('leaves a load in flight alone', () => {
    settleAndAge([sub(1, 1)]);
    store.load();
    const pendingLoad = ctrl.expectOne(list);
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

    store.reloadCountsIfStale();
    ctrl.expectNone(countsUrl);
    expect(pendingLoad.cancelled).toBe(false);
    pendingLoad.flush(fullCounts([sub(1, 4)]));
  });
});
