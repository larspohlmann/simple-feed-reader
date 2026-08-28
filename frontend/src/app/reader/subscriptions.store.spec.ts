import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { TokenStore } from '../core/token.store';
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

  it('loads sidebar counts at most once every ten seconds', () => {
    jest.useFakeTimers({ now: new Date('2026-08-27T16:00:00Z') });

    store.loadIfStale();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 2)],
      favoritesCount: 0,
      keptCount: 0,
    });
    store.loadIfStale();
    ctrl.expectNone('https://api.test/api/subscriptions');

    jest.advanceTimersByTime(10_000);
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

  it('captures a problem on error', () => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ type: 'x', title: 't', status: 500 }, { status: 500, statusText: 'err' });
    expect(store.error()?.status).toBe(500);
    expect(store.loading()).toBe(false);
  });
});
