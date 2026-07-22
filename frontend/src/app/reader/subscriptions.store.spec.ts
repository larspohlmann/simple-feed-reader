import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { SubscriptionsStore, buildTagTree, sumUnread, untaggedSubs } from './subscriptions.store';
import { SubscriptionDto } from './models';

const tag = (id: number, name: string) => ({ id, name, color: null, icon: null });
const sub = (
  id: number,
  unread: number,
  tags = [] as ReturnType<typeof tag>[],
): SubscriptionDto => ({
  id,
  title: `s${id}`,
  customTitle: null,
  feedUrl: `https://f/${id}`,
  siteUrl: null,
  status: 'active',
  createdAt: 'x',
  tags,
  unreadCount: unread,
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
});

describe('SubscriptionsStore', () => {
  let store: SubscriptionsStore;
  let ctrl: HttpTestingController;
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(SubscriptionsStore);
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loads and exposes derived signals', () => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscriptions: [sub(1, 3, [tag(20, 'Tech')]), sub(2, 6, [tag(20, 'Tech')])] });
    expect(store.totalUnread()).toBe(9);
    expect(store.tagTree()[0].unreadCount).toBe(9);
    expect(store.loading()).toBe(false);
  });

  it('optimistically decrements and zeroes unread', () => {
    store.load();
    ctrl
      .expectOne('https://api.test/api/subscriptions')
      .flush({ subscriptions: [sub(1, 3, [tag(20, 'Tech')]), sub(2, 6)] });
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
