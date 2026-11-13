import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { ManageActions } from './manage-actions.service';
import { UNSUBSCRIBE_REQUESTED } from './edit-subscription-dialog.component';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { TagsStore } from '../../state/tags.store';
import { RefreshService } from '../../state/refresh.service';
import { EntriesStore } from '../../state/entries.store';
import { RefreshReport, SubscriptionDto, TagDto } from '../../models';
import { makeSubscription } from '../../testing/subscription.factory';
import { heldUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { ToastService, CONFIRMATION_DURATION_MS } from '../../../shared/toast/toast.service';
import { TranslocoService } from '@jsverse/transloco';
import { INCLUDED_EVERYWHERE } from '../../testing/tag.factory';

const BASE = 'https://api.test';

const sub: SubscriptionDto = makeSubscription({
  id: 5,
  feedId: 50,
  title: 'Heise',
  feedUrl: 'u',
  createdAt: 'x',
});
const tag: TagDto = {
  id: 3,
  name: 'Tech',
  color: null,
  icon: null,
  position: 0,
  parentId: null,
  ...INCLUDED_EVERYWHERE,
};

describe('ManageActions', () => {
  let service: ManageActions;
  let http: HttpTestingController;
  let closed: unknown;
  let savedSearchesLoad: jest.SpyInstance;
  const open = jest.fn(() => ({ closed: of(closed) }));

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: { open } },
      ],
    });
    service = TestBed.inject(ManageActions);
    http = TestBed.inject(HttpTestingController);
    savedSearchesLoad = jest
      .spyOn(TestBed.inject(SavedSearchesStore), 'load')
      .mockImplementation(() => undefined);
    open.mockClear();
  });
  afterEach(() => http.verify());

  it('reloads subscriptions after a successful edit', () => {
    closed = sub; // dialog closed with an updated subscription
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.editSubscription(sub);
    expect(spy).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('reloads saved searches after an edit that switched the saved-search flag', () => {
    closed = { ...sub, includeInSavedSearches: !sub.includeInSavedSearches };
    jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.editSubscription(sub);
    expect(savedSearchesLoad).toHaveBeenCalled();
  });

  it('refreshes a search whose query the edit changed, and only then', () => {
    const search = makeSubscription({
      id: 9,
      feedId: 90,
      social: { network: 'bluesky', accountId: 3, kind: 'search', query: 'rss' },
    });
    jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    const run = jest
      .spyOn(TestBed.inject(RefreshService), 'run')
      .mockImplementation(() => undefined);

    closed = search;
    service.editSubscription(search);
    expect(run).not.toHaveBeenCalled();

    closed = { ...search, social: { ...search.social, query: 'atom' } };
    service.editSubscription(search);
    expect(run).toHaveBeenCalledWith(undefined, { kind: 'feed', feedId: 90 });
  });

  it('reloads the entry list at once after a new query, even while another refresh runs', () => {
    const search = makeSubscription({
      id: 9,
      feedId: 90,
      social: { network: 'bluesky', accountId: 3, kind: 'search', query: 'rss' },
    });
    jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    const reload = jest
      .spyOn(TestBed.inject(EntriesStore), 'reload')
      .mockImplementation(() => undefined);
    TestBed.inject(RefreshService).running.set(true);

    closed = search;
    service.editSubscription(search);
    expect(reload).not.toHaveBeenCalled();

    closed = { ...search, social: { ...search.social, query: 'atom' } };
    service.editSubscription(search);
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('opens the unsubscribe confirmation when the edit dialog asks for it', () => {
    open.mockReturnValueOnce({ closed: of(UNSUBSCRIBE_REQUESTED) });
    closed = true; // the confirmation that follows returns true
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.editSubscription(sub);
    expect(open).toHaveBeenCalledTimes(2);
    http.expectOne({ method: 'DELETE', url: 'https://api.test/api/subscriptions/5' }).flush(null);
    expect(spy).toHaveBeenCalledTimes(1);
  });

  it('unsubscribe: on confirm, DELETEs then reloads', () => {
    closed = true; // confirm dialog returned true
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.unsubscribe(sub);
    http.expectOne('https://api.test/api/subscriptions/5').flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it("unsubscribe: names an unrenamed Home by the Home's label", () => {
    closed = undefined;
    service.unsubscribe(heldUnrenamedHome(6));

    expect(JSON.stringify(open.mock.calls[0])).toContain('Home@bsky');
  });

  it('unsubscribe: says where favourites and kept posts go', () => {
    closed = undefined;
    service.unsubscribe(
      makeSubscription({
        id: 7,
        title: 'Bob',
        social: { network: 'bluesky', accountId: null, kind: 'profile' },
      }),
    );

    expect(JSON.stringify(open.mock.calls[0])).toContain(
      'Favourites and kept posts move to Saved from Bluesky.',
    );
  });

  it('unsubscribe: warns that a saved feed’s posts are deleted for good', () => {
    closed = undefined;
    service.unsubscribe(
      makeSubscription({ id: 8, title: 'Saved from feeds', sourceFormat: 'saved' }),
    );

    expect(JSON.stringify(open.mock.calls[0])).toContain('Its saved posts are deleted for good.');
  });

  it('unsubscribe: on cancel, does nothing', () => {
    closed = undefined;
    service.unsubscribe(sub);
    http.expectNone('https://api.test/api/subscriptions/5');
  });

  it('moveFeedToTag: PATCHes /move-to-tag with from/to/position then reloads', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const spy = jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.moveFeedToTag(tagged, { fromTagId: 3, toTagId: 7, position: 1 });
    const testRequest = http.expectOne('https://api.test/api/subscriptions/5/move-to-tag');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ fromTagId: 3, toTagId: 7, position: 1 });
    testRequest.flush({ subscription: tagged });
    expect(spy).toHaveBeenCalled();
  });

  it('moveFeedToTag: optimistically drops the source tag and adds the target', () => {
    const store = TestBed.inject(SubscriptionsStore);
    const tags = TestBed.inject(TagsStore);
    const news: TagDto = {
      id: 7,
      name: 'News',
      color: null,
      icon: null,
      position: 1,
      parentId: null,
      ...INCLUDED_EVERYWHERE,
    };
    tags.tags.set([tag, news]);
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    store.storedSubscriptions.set([tagged]);
    jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.moveFeedToTag(tagged, { fromTagId: 3, toTagId: 7, position: 0 });
    expect(
      store
        .subscriptions()
        .find((x) => x.id === 5)!
        .tags.map((assignedTag) => assignedTag.id),
    ).toEqual([7]);
    http
      .expectOne('https://api.test/api/subscriptions/5/move-to-tag')
      .flush({ subscription: tagged });
  });

  it('moveFeedToTag: does nothing when the source and target lists are the same', () => {
    service.moveFeedToTag(sub, { fromTagId: 3, toTagId: 3, position: 0 });
    http.expectNone('https://api.test/api/subscriptions/5/move-to-tag');
  });

  it('setIncludeInAllItems: PATCHes the full body with the flag flipped and optimistically updates the store', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const spy = jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.setIncludeInAllItems(tagged, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInAllItems).toBe(false);
    const testRequest = http.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      customTitle: tagged.customTitle,
      tagIds: [3],
      includeInAllItems: false,
    });
    testRequest.flush({ subscription: { ...tagged, includeInAllItems: false } });
    expect(spy).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('setIncludeInForYou: PATCHes the full body with the flag flipped and optimistically updates the store', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const spy = jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.setIncludeInForYou(tagged, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInForYou).toBe(false);
    const testRequest = http.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      customTitle: tagged.customTitle,
      tagIds: [3],
      includeInForYou: false,
    });
    testRequest.flush({ subscription: { ...tagged, includeInForYou: false } });
    expect(spy).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('setIncludeInSavedSearches: PATCHes the flag, then reloads subscriptions and saved searches', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const subscriptionsLoad = jest
      .spyOn(store, 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.setIncludeInSavedSearches(tagged, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInSavedSearches).toBe(false);
    const testRequest = http.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      customTitle: tagged.customTitle,
      tagIds: [3],
      includeInSavedSearches: false,
    });
    testRequest.flush({ subscription: { ...tagged, includeInSavedSearches: false } });
    expect(subscriptionsLoad).toHaveBeenCalled();
    expect(savedSearchesLoad).toHaveBeenCalled();
  });

  it('reorderUntagged: PATCHes /api/subscriptions/reorder then reloads subs', () => {
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.reorderUntagged([9, 8, 7]);
    const testRequest = http.expectOne('https://api.test/api/subscriptions/reorder');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ subscriptionIds: [9, 8, 7] });
    testRequest.flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTagFeeds: PATCHes /api/tags/{id}/feed-order then reloads subs', () => {
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.reorderTagFeeds(4, [2, 1]);
    const testRequest = http.expectOne('https://api.test/api/tags/4/feed-order');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ subscriptionIds: [2, 1] });
    testRequest.flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('addFeed: opens the add-feed dialog and reloads on a successful subscribe', () => {
    closed = sub;
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);

    service.addFeed().subscribe();

    expect(open).toHaveBeenCalled();
    expect(spy).toHaveBeenCalled();
  });

  describe('retryFeed', () => {
    const refreshReport = (over: Partial<RefreshReport>): RefreshReport => ({
      status: 'completed',
      progress: { done: 1, total: 1 },
      fetched: 0,
      notModified: 0,
      failed: 0,
      throttled: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
      ...over,
    });
    const emptyResponse = () => ({
      subscriptions: [],
      favoritesCount: 0,
      unreadTotals: {
        allItems: 0,
        forYou: 0,
        tags: [],
        networks: [],
        groups: { tags: 0, feeds: 0 },
      },
      keptCount: 0,
      viewedCount: 0,
      trending: { window: 'day', unopened: 0 },
    });
    const flushReload = (http: HttpTestingController): void => {
      http.expectOne(`${BASE}/api/subscriptions`).flush(emptyResponse());
    };

    let http: HttpTestingController;
    afterEach(() => http.verify());

    // Own TestBed: ManageActions and ToastService share the Dialog token, so a real
    // ToastService.show() call here needs the stub replaced rather than left to the outer `open` mock.
    function setup() {
      TestBed.resetTestingModule();
      const toast = { show: jest.fn() };
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          provideHttpClient(),
          provideHttpClientTesting(),
          { provide: API_BASE_URL, useValue: BASE },
          { provide: Dialog, useValue: { open } },
          { provide: ToastService, useValue: toast },
        ],
      });
      const actions = TestBed.inject(ManageActions);
      http = TestBed.inject(HttpTestingController);
      // Resolve the store, else the post-retry quiet reload no-ops — exactly as
      // it does before a page has loaded its subscriptions.
      const store = TestBed.inject(SubscriptionsStore);
      store.load();
      http.expectOne(`${BASE}/api/subscriptions`).flush(emptyResponse());
      // Spy without a mock implementation: translate() still runs for real
      // (the shipped en/de dictionaries), so a missing `settings.health.retry.*`
      // key still resolves the same way production would — the spy only lets
      // the tests see exactly which key and params retryFeed passed in, which
      // the rendered (key-echoing) string alone cannot distinguish.
      const translate = jest.spyOn(TestBed.inject(TranslocoService), 'translate');
      return { actions, http, toast, translate };
    }

    it('POSTs a single-feed refresh scoped by feedId', () => {
      const { actions, http } = setup();

      actions.retryFeed(sub).subscribe();

      const testRequest = http.expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`);
      expect(testRequest.request.method).toBe('POST');
      testRequest.flush(refreshReport({ failed: 0, fetched: 1 }));
      flushReload(http);
    });

    it('emits false, toasts recovery and quietly reloads when nothing failed', () => {
      const { actions, http, toast, translate } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush(refreshReport({ failed: 0, fetched: 1, notModified: 0 }));

      expect(stillFailing).toBe(false);
      expect(translate).toHaveBeenCalledWith('settings.health.retry.recovered', {
        title: sub.title,
      });
      expect(toast.show).toHaveBeenCalledWith(
        expect.objectContaining({ durationMs: CONFIRMATION_DURATION_MS }),
      );
      flushReload(http);
    });

    it('treats a pure 304 (no fetch, no failure) as recovered', () => {
      const { actions, http, translate } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush(refreshReport({ failed: 0, fetched: 0, notModified: 1 }));

      expect(stillFailing).toBe(false);
      expect(translate).toHaveBeenCalledWith('settings.health.retry.recovered', {
        title: sub.title,
      });
      flushReload(http);
    });

    it('emits true and shows no toast when the feed is still failing', () => {
      const { actions, http, toast } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush(refreshReport({ failed: 1, fetched: 0, notModified: 0 }));

      expect(stillFailing).toBe(true);
      expect(toast.show).not.toHaveBeenCalled();
      flushReload(http);
    });

    it('emits true and does not reload when the refresh request fails', () => {
      const { actions, http, toast } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush('fail', { status: 500, statusText: 'Server Error' });

      expect(stillFailing).toBe(true);
      expect(toast.show).not.toHaveBeenCalled();
      http.expectNone(`${BASE}/api/subscriptions`);
    });
  });
});
