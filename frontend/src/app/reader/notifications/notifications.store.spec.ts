import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { ToastService } from '../../shared/toast/toast.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { NotificationGroupDto, NotificationsPageDto } from './notification.models';
import { NotificationsStore } from './notifications.store';

describe('NotificationsStore (#2240)', () => {
  const list = (network: string) => `https://api.test/api/me/notifications?network=${network}`;
  const seenUrl = 'https://api.test/api/me/notifications/seen';
  const group = (id: number, over: Partial<NotificationGroupDto> = {}): NotificationGroupDto => ({
    id,
    kind: 'like',
    account: '@a',
    actors: [],
    actorCount: 0,
    excerpt: null,
    answeredExcerpt: null,
    threadTitle: null,
    threadPlace: null,
    threadBy: null,
    onComment: false,
    linkUrl: 'https://example.test/post',
    entryId: null,
    latestAt: '2026-11-16T10:00:00+00:00',
    seen: true,
    ...over,
  });
  const page = (
    groups: NotificationGroupDto[],
    over: Partial<NotificationsPageDto> = {},
  ): NotificationsPageDto => ({
    groups,
    nextCursor: null,
    scopeMissing: [],
    needsReconnect: [],
    ...over,
  });

  let store: NotificationsStore;
  let http: HttpTestingController;
  const showProblem = jest.fn();
  const reloadCountsQuietly = jest.fn();

  beforeEach(() => {
    showProblem.mockClear();
    reloadCountsQuietly.mockClear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: ToastService, useValue: { showProblem } },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
      ],
    });
    store = TestBed.inject(NotificationsStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  const ids = () => store.groups().map((item) => item.id);

  function openWithUnseen(): void {
    store.open('bluesky');
    http
      .expectOne(list('bluesky'))
      .flush(page([group(9, { seen: false }), group(7, { seen: false })]));
  }

  it("opens a network's first page", () => {
    store.open('bluesky');
    http
      .expectOne(list('bluesky'))
      .flush(page([group(9), group(7)], { nextCursor: 7, scopeMissing: ['@a'] }));

    expect(ids()).toEqual([9, 7]);
    expect(store.nextCursor()).toBe(7);
    expect(store.scopeMissing()).toEqual(['@a']);
    expect(store.loading()).toBe(false);
  });

  it('appends the next page', () => {
    store.open('bluesky');
    http.expectOne(list('bluesky')).flush(page([group(9), group(7)], { nextCursor: 7 }));

    store.loadMore();
    http.expectOne(`${list('bluesky')}&cursor=7`).flush(page([group(5)]));

    expect(ids()).toEqual([9, 7, 5]);
    expect(store.nextCursor()).toBeNull();
  });

  it('drops a page answered for a network the user has left', () => {
    store.open('bluesky');
    const stale = http.expectOne(list('bluesky'));
    store.open('mastodon');

    expect(stale.cancelled).toBe(true);
    http.expectOne(list('mastodon')).flush(page([group(3)]));
    expect(ids()).toEqual([3]);
    expect(store.network()).toBe('mastodon');
  });

  it('marks everything shown seen up to the newest group', () => {
    openWithUnseen();

    store.markAllSeen();
    const request = http.expectOne(seenUrl);
    expect(request.request.body).toEqual({ network: 'bluesky', upTo: 9 });
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(store.groups().every((item) => item.seen)).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(store.marking()).toBe(false);
  });

  it('toasts the problem and reloads the counts and the list when marking fails', () => {
    openWithUnseen();

    store.markAllSeen();
    http
      .expectOne(seenUrl)
      .flush(
        { type: 'social_network_unreachable', title: 'Unreachable', status: 502 },
        { status: 502, statusText: 'Bad Gateway' },
      );

    expect(showProblem).toHaveBeenCalledTimes(1);
    expect(showProblem).toHaveBeenCalledWith(expect.objectContaining({ status: 502 }));
    expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
    expect(store.marking()).toBe(false);
    http
      .expectOne(list('bluesky'))
      .flush(page([group(9, { seen: true }), group(7, { seen: false })]));
    expect(store.groups().map((item) => item.seen)).toEqual([true, false]);
  });

  it('marks nothing when the list is empty', () => {
    store.open('bluesky');
    http.expectOne(list('bluesky')).flush(page([]));

    store.markAllSeen();

    http.expectNone(seenUrl);
  });

  it('reports a failed load and retries it on reload', () => {
    store.open('bluesky');
    http.expectOne(list('bluesky')).flush(null, { status: 500, statusText: 'Server Error' });

    expect(store.error()?.status).toBe(500);
    expect(store.groups()).toEqual([]);

    store.reload();
    http.expectOne(list('bluesky')).flush(page([group(1)]));
    expect(ids()).toEqual([1]);
    expect(store.error()).toBeNull();
  });

  it('clears the scope and reconnect hints when the first page fails', () => {
    store.open('bluesky');
    http
      .expectOne(list('bluesky'))
      .flush(page([group(1)], { scopeMissing: ['@a'], needsReconnect: ['@b'] }));

    store.reload();
    http.expectOne(list('bluesky')).flush(null, { status: 500, statusText: 'Server Error' });

    expect(store.scopeMissing()).toEqual([]);
    expect(store.needsReconnect()).toEqual([]);
  });

  it('ignores a seen answer once another network is open', () => {
    openWithUnseen();
    store.markAllSeen();
    const marking = http.expectOne(seenUrl);

    store.open('mastodon');
    http.expectOne(list('mastodon')).flush(page([group(3, { seen: false })]));

    expect(marking.cancelled).toBe(true);
    expect(store.marking()).toBe(false);
    expect(store.groups().map((item) => item.seen)).toEqual([false]);
    expect(reloadCountsQuietly).not.toHaveBeenCalled();
  });

  it('leaves a group newer than the marked position unseen after a mid-flight reload', () => {
    openWithUnseen();
    store.markAllSeen();
    const marking = http.expectOne(seenUrl);

    store.reload();
    http
      .expectOne(list('bluesky'))
      .flush(page([group(12, { seen: false }), group(9, { seen: false })]));
    marking.flush(null, { status: 204, statusText: 'No Content' });

    expect(store.groups().map((item) => item.seen)).toEqual([false, true]);
  });

  it("clears the previous network's list on open and marks nothing while loading", () => {
    store.open('bluesky');
    http
      .expectOne(list('bluesky'))
      .flush(page([group(9, { seen: false })], { scopeMissing: ['@a'], needsReconnect: ['@b'] }));

    store.open('mastodon');
    store.markAllSeen();

    expect(store.groups()).toEqual([]);
    expect(store.scopeMissing()).toEqual([]);
    expect(store.needsReconnect()).toEqual([]);
    http.expectNone(seenUrl);
    http.expectOne(list('mastodon')).flush(page([]));
  });

  it('clears a failure when loadMore starts again', () => {
    store.open('bluesky');
    http.expectOne(list('bluesky')).flush(page([group(9)], { nextCursor: 9 }));
    store.loadMore();
    http
      .expectOne(`${list('bluesky')}&cursor=9`)
      .flush(null, { status: 500, statusText: 'Server Error' });
    expect(store.error()?.status).toBe(500);

    store.loadMore();

    expect(store.error()).toBeNull();
    http.expectOne(`${list('bluesky')}&cursor=9`).flush(page([group(5)]));
    expect(ids()).toEqual([9, 5]);
  });

  describe('refresh (#2502)', () => {
    const refreshUrl = 'https://api.test/api/me/notifications/refresh';

    it('polls the network, then reads the list and the counts', () => {
      store.open('bluesky');
      http.expectOne(list('bluesky')).flush(page([group(7)]));

      store.refresh();
      const poll = http.expectOne(refreshUrl);
      expect(poll.request.method).toBe('POST');
      expect(poll.request.body).toEqual({ network: 'bluesky' });
      expect(store.loading()).toBe(true);
      http.expectNone(list('bluesky'));
      expect(reloadCountsQuietly).not.toHaveBeenCalled();

      poll.flush(null, { status: 204, statusText: 'No Content' });
      expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
      http.expectOne(list('bluesky')).flush(page([group(9), group(7)]));
      expect(ids()).toEqual([9, 7]);
      expect(store.loading()).toBe(false);
    });

    it('still reloads the list and the counts when the poll fails', () => {
      store.open('bluesky');
      http.expectOne(list('bluesky')).flush(page([group(7)]));

      store.refresh();
      http
        .expectOne(refreshUrl)
        .flush(
          { type: 'rate_limited', title: 'Too many requests', status: 429 },
          { status: 429, statusText: 'Too Many Requests' },
        );

      expect(showProblem).toHaveBeenCalledWith(expect.objectContaining({ status: 429 }));
      expect(reloadCountsQuietly).toHaveBeenCalledTimes(1);
      http.expectOne(list('bluesky')).flush(page([group(7)]));
      expect(ids()).toEqual([7]);
      expect(store.error()).toBeNull();
    });

    it('drops a poll answered after switching networks', () => {
      store.open('bluesky');
      http.expectOne(list('bluesky')).flush(page([group(7)]));

      store.refresh();
      const poll = http.expectOne(refreshUrl);
      store.open('mastodon');

      expect(poll.cancelled).toBe(true);
      http.expectOne(list('mastodon')).flush(page([group(3)]));
      http.expectNone(list('bluesky'));
      expect(ids()).toEqual([3]);
    });

    it('does nothing before a network is open', () => {
      store.refresh();

      http.expectNone(refreshUrl);
    });
  });
});
