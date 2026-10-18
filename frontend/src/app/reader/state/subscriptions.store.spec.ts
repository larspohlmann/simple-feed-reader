import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { effect } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/auth/token.store';
import { ReaderApi } from '../reader-api';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { socialProfiles } from './social-sections';
import { SubscriptionsStore } from './subscriptions.store';
import { SubscriptionRows } from './subscription-rows';
import { buildTagTree, sumEntries, untaggedSubscriptions } from './subscription-derivations';
import { SubscriptionDto, UnreadTotalsDto } from '../models';

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
  lastNewContentAt: null,
  nextFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags,
  unreadCount: unread,
  entryCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
  social: null,
});

interface CountTotals {
  favorites?: number;
  kept?: number;
  viewed?: number;
  unreadTotals?: Partial<UnreadTotalsDto>;
}

const unreadTotalsDto = (over: Partial<UnreadTotalsDto> = {}): UnreadTotalsDto => ({
  allItems: 0,
  tags: [],
  networks: [],
  ...over,
});

const counts = <Row>(subscriptions: Row[], totals: CountTotals = {}) => ({
  subscriptions,
  favoritesCount: totals.favorites ?? 0,
  keptCount: totals.kept ?? 0,
  viewedCount: totals.viewed ?? 0,
  unreadTotals: unreadTotalsDto(totals.unreadTotals),
});

describe('subscription derivations', () => {
  const subscriptions = [
    sub(1, 3, [tag(10, 'News'), tag(20, 'Tech')]),
    sub(2, 6, [tag(20, 'Tech')]),
    sub(3, 0, []),
  ];
  it("takes a tag's unread from the tag totals, not the sum of its feeds", () => {
    const tree = buildTagTree(
      subscriptions,
      [],
      new Map([
        [10, 2],
        [20, 5],
      ]),
    );
    expect(tree.map((node) => [node.tag.name, node.unreadCount])).toEqual([
      ['News', 2],
      ['Tech', 5],
    ]);
    expect(
      tree
        .find((node) => node.tag.name === 'Tech')!
        .subscriptions.map((subscription) => subscription.id),
    ).toEqual([1, 2]);
  });
  it('lists untagged subs', () => {
    expect(untaggedSubscriptions(subscriptions).map((subscription) => subscription.id)).toEqual([
      3,
    ]);
  });

  it('orders untagged feeds by their position', () => {
    const at = (id: number, position: number): SubscriptionDto => ({ ...sub(id, 0), position });
    expect(
      untaggedSubscriptions([at(1, 2), at(2, 0), at(3, 1)]).map((subscription) => subscription.id),
    ).toEqual([2, 3, 1]);
  });

  it("reads an excluded feed's tag unread from the tag totals", () => {
    const excluded = { ...sub(2, 8, [tag(3, 'Tech')]), includeInAllItems: false };
    const tree = buildTagTree([excluded], [], new Map([[3, 8]]));
    expect(tree[0].unreadCount).toBe(8);
  });

  it("sums a tag's entry counts from its feeds, like its unread count", () => {
    const tree = buildTagTree([
      { ...sub(1, 0, [tag(7, 'News')]), entryCount: 30 },
      { ...sub(2, 0, [tag(7, 'News')]), entryCount: 12 },
    ]);
    expect(tree[0].entryCount).toBe(42);
  });

  it('totals entries over the feeds included in All items only', () => {
    expect(
      sumEntries([
        { ...sub(1, 0, []), entryCount: 30 },
        { ...sub(2, 0, []), entryCount: 12, includeInAllItems: false },
      ]),
    ).toBe(30);
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
    const subscriptions = [inTag(1, 20, 1), inTag(2, 20, 0), inTag(3, 10, 0)];
    const tree = buildTagTree(subscriptions, orderedTags);
    // Nodes follow the tag order, and the empty tag still appears.
    expect(tree.map((node) => node.tag.name)).toEqual(['Tech', 'News', 'Empty']);
    // Feeds within Tech follow their per-tag position: sub 2 (0) before sub 1 (1).
    expect(tree[0].subscriptions.map((subscription) => subscription.id)).toEqual([2, 1]);
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
      imports: [provideTranslocoTesting()],
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
      unreadTotals: unreadTotalsDto({ allItems: 4, tags: [{ id: 20, unreadCount: 4 }] }),
      keptCount: 2,
    });
    expect(store.totalUnread()).toBe(4);
    expect(store.tagTree()[0].unreadCount).toBe(4);
    expect(store.favoritesCount()).toBe(4);
    expect(store.keptCount()).toBe(2);
    expect(store.loading()).toBe(false);
  });

  it('cancels an older subscription request when a newer load starts', () => {
    store.load();
    const olderRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    store.load();
    const newerRequest = ctrl.expectOne('https://api.test/api/subscriptions');

    newerRequest.flush({
      subscriptions: [sub(1, 5)],
      favoritesCount: 0,
      keptCount: 0,
      unreadTotals: unreadTotalsDto({ allItems: 5 }),
    });

    expect(olderRequest.cancelled).toBe(true);
    expect(store.totalUnread()).toBe(5);
  });

  it('cancels every older request before an identity change can expose it', () => {
    store.load();
    store.load();
    const [olderRequest, newerRequest] = ctrl.match('https://api.test/api/subscriptions');

    newerRequest.flush({
      subscriptions: [sub(1, 5)],
      favoritesCount: 0,
      keptCount: 0,
      unreadTotals: unreadTotalsDto(),
    });
    tokens.clear();
    TestBed.tick();

    expect(olderRequest.cancelled).toBe(true);
  });

  it('makes a previous empty result unresolved while the current account reloads', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [],
      favoritesCount: 0,
      unreadTotals: unreadTotalsDto(),
      keptCount: 0,
      viewedCount: 0,
    });
    expect(store.resolved()).toBe(true);

    store.load();

    expect(store.resolved()).toBe(false);
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3)],
      favoritesCount: 0,
      unreadTotals: unreadTotalsDto(),
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
        unreadTotals: unreadTotalsDto(),
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
        unreadTotals: unreadTotalsDto(),
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
        unreadTotals: unreadTotalsDto(),
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
      unreadTotals: unreadTotalsDto(),
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
      unreadTotals: unreadTotalsDto(),
      keptCount: 0,
    });

    jest.useRealTimers();
  });

  it('optimistically bumps favourite/kept totals, clamped at zero', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3)],
      favoritesCount: 1,
      keptCount: 0,
      unreadTotals: unreadTotalsDto(),
    });
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
      unreadTotals: unreadTotalsDto(),
      keptCount: 0,
    });
    store.decrementUnread(1);
    expect(store.subscriptions().find((subscription) => subscription.id === 1)!.unreadCount).toBe(
      2,
    );
    store.decrementUnread(1, 99);
    expect(store.subscriptions().find((subscription) => subscription.id === 1)!.unreadCount).toBe(
      0,
    );
    store.zeroUnread({ subscription: 2 });
    expect(store.subscriptions().find((subscription) => subscription.id === 2)!.unreadCount).toBe(
      0,
    );
  });

  it('optimistically patches the exclusion flags in place', () => {
    store.load();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      subscriptions: [sub(1, 3), sub(2, 6)],
      favoritesCount: 0,
      unreadTotals: unreadTotalsDto(),
      keptCount: 0,
    });
    store.patchLocal(1, { includeInAllItems: false });
    expect(
      store.subscriptions().find((subscription) => subscription.id === 1)!.includeInAllItems,
    ).toBe(false);
    expect(
      store.subscriptions().find((subscription) => subscription.id === 1)!.includeInForYou,
    ).toBe(true);
    expect(
      store.subscriptions().find((subscription) => subscription.id === 2)!.includeInAllItems,
    ).toBe(true);
    store.patchLocal(1, { includeInForYou: false });
    expect(
      store.subscriptions().find((subscription) => subscription.id === 1)!.includeInForYou,
    ).toBe(false);
  });

  it('exposes unhealthy feeds and their count', () => {
    store.storedSubscriptions.set([
      {
        ...sub(1, 0),
        title: 'Bravo',
        status: 'erroring',
        lastSuccessfulFetchAt: '2020-01-01T00:00:00Z',
      },
      { ...sub(2, 0), title: 'Alpha', status: 'gone' },
      { ...sub(3, 0), status: 'active' },
    ]);
    expect(store.unhealthy().map((subscription) => subscription.id)).toEqual([2, 1]);
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

  describe('with social feeds', () => {
    const taggedList: SubscriptionDto = {
      ...sub(5, 2, [tag(20, 'Tech')]),
      includeInAllItems: false,
      social: { network: 'bluesky', accountId: 3, kind: 'list' },
    };
    const untaggedHome: SubscriptionDto = {
      ...sub(6, 4),
      includeInAllItems: false,
      social: { network: 'bluesky', accountId: 3, kind: 'home' },
    };
    const untaggedProfile: SubscriptionDto = {
      ...sub(7, 8),
      social: { network: 'mastodon', accountId: null, kind: 'profile' },
    };

    beforeEach(() => {
      store.load();
      ctrl.expectOne('https://api.test/api/subscriptions').flush({
        ...counts(
          [sub(1, 1, [tag(20, 'Tech')]), sub(2, 16, []), taggedList, untaggedHome, untaggedProfile],
          {
            unreadTotals: {
              allItems: 25,
              tags: [{ id: 20, unreadCount: 3 }],
              networks: [
                { network: 'bluesky', unreadCount: 6, profilesUnreadCount: 0 },
                { network: 'mastodon', unreadCount: 8, profilesUnreadCount: 8 },
              ],
            },
          },
        ),
        socialAccounts: [
          { id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' },
        ],
      });
    });

    it('lists a tagged private feed under its tag and counts its unread there', () => {
      const tech = store.tagTree().find((node) => node.tag.name === 'Tech');

      expect(tech?.subscriptions.map((subscription) => subscription.id)).toEqual([1, 5]);
      expect(tech?.unreadCount).toBe(3);
    });

    it('never lists a social feed in the untagged feeds', () => {
      expect(store.untagged().map((subscription) => subscription.id)).toEqual([2]);
    });

    it('keeps a private feed out of the All items total but counts a profile feed', () => {
      expect(store.totalUnread()).toBe(1 + 16 + 8);
    });

    it('groups social feeds by network, private ones per account and profile feeds as the Feeds group, each Home titled', () => {
      expect(store.socialSections()).toEqual([
        {
          network: 'bluesky',
          networkAccounts: [
            { id: 3, network: 'bluesky', handle: '@alice.bsky.social', status: 'connected' },
          ],
          accounts: [
            {
              accountId: 3,
              handle: '@alice.bsky.social',
              subscriptions: [{ ...untaggedHome, title: 'Home@bsky' }, taggedList],
            },
          ],
          profiles: socialProfiles([], 0),
          unreadCount: 6,
          entryCount: 0,
          saved: null,
          showsHandles: false,
        },
        {
          network: 'mastodon',
          networkAccounts: [],
          accounts: [],
          profiles: socialProfiles([untaggedProfile], 8),
          unreadCount: 8,
          entryCount: 0,
          saved: null,
          showsHandles: false,
        },
      ]);
    });

    it('zeroes exactly the feeds of a network marked read', () => {
      store.zeroUnread({ network: 'bluesky' });

      const unread = (id: number) =>
        store.subscriptions().find((subscription) => subscription.id === id)?.unreadCount;
      expect([unread(1), unread(2), unread(5), unread(6), unread(7)]).toEqual([1, 16, 0, 0, 8]);
    });

    it("zeroes only a network's profile feeds when the Feeds row is marked read", () => {
      store.zeroUnread({ network: 'mastodon', feedKind: 'profile' });
      store.zeroUnread({ network: 'bluesky', feedKind: 'profile' });

      const unread = (id: number) =>
        store.subscriptions().find((subscription) => subscription.id === id)?.unreadCount;
      expect([unread(1), unread(2), unread(5), unread(6), unread(7)]).toEqual([1, 16, 2, 4, 0]);
    });

    it('marks All items read only in the feeds All items holds, as the backend does', () => {
      store.zeroUnread('all');

      const unread = (id: number) =>
        store.subscriptions().find((subscription) => subscription.id === id)?.unreadCount;
      expect([unread(1), unread(2), unread(5), unread(6), unread(7)]).toEqual([0, 0, 2, 4, 0]);
    });

    it("reads each feed's network from the loaded list and forgets it on logout", () => {
      const rows = TestBed.inject(SubscriptionRows);
      const networkOf = (id: number) => rows.find(id)?.social?.network ?? null;
      expect([networkOf(6), networkOf(7), networkOf(1)]).toEqual(['bluesky', 'mastodon', null]);

      tokens.clear();
      TestBed.tick();

      expect(networkOf(6)).toBeNull();
    });

    it("zeroes a tag's private feeds along with the rest when the tag is marked read", () => {
      store.zeroUnread({ tag: 20 });

      const unread = (id: number) =>
        store.subscriptions().find((subscription) => subscription.id === id)?.unreadCount;
      expect([unread(1), unread(5), unread(6)]).toEqual([0, 0, 4]);
    });
  });
});

describe('SubscriptionsStore quiet reload', () => {
  let store: SubscriptionsStore;
  let ctrl: HttpTestingController;

  /** A settled store, one poll interval old, so a quiet reload may fire. */
  const settleAndAge = (subscriptions: SubscriptionDto[]) => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush(counts(subscriptions, { favorites: 1, kept: 2, viewed: 3 }));
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
  };

  beforeEach(() => {
    localStorage.clear();
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
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

    ctrl.expectOne('https://api.test/api/subscriptions').flush(
      counts([sub(1, 7)], {
        favorites: 4,
        kept: 5,
        viewed: 6,
        unreadTotals: { allItems: 7 },
      }),
    );
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
    slowTick.flush(counts([sub(1, 4)], { unreadTotals: { allItems: 4 } }));
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

    pendingLoad.flush(counts([sub(1, 4)], { unreadTotals: { allItems: 4 } }));
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
    tick.flush(counts([sub(1, 5)], { favorites: 9, kept: 9, viewed: 9 }));

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
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush(counts([sub(1, 2)], { favorites: 7, kept: 8, viewed: 9 }));

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
    store.load();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush(counts([sub(1, 5)], { unreadTotals: { allItems: 5 } }));
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

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
    unreadTotals: unreadTotalsDto({ allItems: unread }),
    keptCount: 0,
    viewedCount: 0,
  });

  it('keeps ticking after a response that never left the process', () => {
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    let unread = 1;
    const subscriptions = jest.fn(() => of(respond(++unread)));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
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

  const countsBody = (
    subscriptions: { id: number; unreadCount: number; entryCount?: number }[],
    totals: CountTotals = {},
  ) =>
    counts(
      subscriptions.map((subscription) => ({ entryCount: 0, ...subscription })),
      totals,
    );

  /** A settled store, one interval old, so a counts tick may fire. */
  const settleAndAge = (subscriptions: SubscriptionDto[]) => {
    store.load();
    ctrl.expectOne(list).flush(counts(subscriptions, { favorites: 1, kept: 2, viewed: 3 }));
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
  };

  beforeEach(() => {
    localStorage.clear();
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
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
    ctrl.expectOne(countsUrl).flush(
      countsBody([{ id: 1, unreadCount: 7 }], {
        favorites: 4,
        kept: 5,
        viewed: 6,
        unreadTotals: { allItems: 7 },
      }),
    );
    expect(store.totalUnread()).toBe(7);
    expect(store.favoritesCount()).toBe(4);
    expect(store.keptCount()).toBe(5);
    expect(store.viewedCount()).toBe(6);
    expect(store.loading()).toBe(false);
  });

  it('adopts the scope totals of a counts tick', () => {
    settleAndAge([sub(1, 2)]);

    store.reloadCountsIfStale();
    ctrl.expectOne(countsUrl).flush(
      countsBody([{ id: 1, unreadCount: 2 }], {
        unreadTotals: { allItems: 1, tags: [{ id: 20, unreadCount: 1 }] },
      }),
    );

    expect(store.unreadTotals().allItems).toBe(1);
    expect(store.unreadTotals().tags.get(20)).toBe(1);
  });

  it('keeps the same totals object when a tick changes none of them', () => {
    settleAndAge([sub(1, 2)]);
    const before = store.unreadTotals();

    store.reloadCountsIfStale();
    ctrl.expectOne(countsUrl).flush(countsBody([{ id: 1, unreadCount: 2 }]));

    expect(store.unreadTotals()).toBe(before);
  });

  it('moves a tag node when the tick changes its tag total', () => {
    settleAndAge([sub(1, 2, [tag(20, 'Tech')])]);
    expect(store.tagTree()[0].unreadCount).toBe(0);

    store.reloadCountsIfStale();
    ctrl.expectOne(countsUrl).flush(
      countsBody([{ id: 1, unreadCount: 2 }], {
        unreadTotals: { tags: [{ id: 20, unreadCount: 2 }] },
      }),
    );

    expect(store.tagTree()[0].unreadCount).toBe(2);
  });

  it('patches unread into the feed rows it already holds', () => {
    settleAndAge([sub(1, 2), sub(2, 9)]);

    store.reloadCountsIfStale();
    ctrl.expectOne(countsUrl).flush(countsBody([{ id: 2, unreadCount: 3 }]));

    // Feed 1 is absent from the payload, so it has no unread entries.
    expect(store.subscriptions().find((subscription) => subscription.id === 1)?.unreadCount).toBe(
      0,
    );
    expect(store.subscriptions().find((subscription) => subscription.id === 2)?.unreadCount).toBe(
      3,
    );
  });

  it('patches entry counts from the counts-only tick', () => {
    settleAndAge([{ ...sub(1, 1, []), entryCount: 3 }]);

    store.reloadCountsIfStale();
    ctrl.expectOne(countsUrl).flush(countsBody([{ id: 1, unreadCount: 1, entryCount: 5 }]));

    expect(store.subscriptions()[0].entryCount).toBe(5);
  });

  it('keeps the same array identity when no count moved', () => {
    settleAndAge([sub(1, 2)]);
    const before = store.subscriptions();

    store.reloadCountsIfStale();
    ctrl
      .expectOne(countsUrl)
      .flush(countsBody([{ id: 1, unreadCount: 2 }], { favorites: 1, kept: 2, viewed: 3 }));

    // Nothing moved, so the array is not replaced and the derived signals do
    // not recompute (#720).
    expect(store.subscriptions()).toBe(before);
  });

  it('drops a response the user has overtaken by reading an entry', () => {
    settleAndAge([sub(1, 5)]);
    store.reloadCountsIfStale();
    const tick = ctrl.expectOne(countsUrl);

    store.decrementUnread(1);
    tick.flush(countsBody([{ id: 1, unreadCount: 5 }], { favorites: 9, kept: 9, viewed: 9 }));

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
    pendingLoad.flush(counts([sub(1, 4)], { unreadTotals: { allItems: 4 } }));
  });
});
