import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { ReaderApi } from './reader-api';
import { PAGE_SIZE } from './list/paging';
import { ReaderContent } from './models';
import { refreshReport } from '../../testing/refresh-report';

describe('ReaderApi', () => {
  let api: ReaderApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(ReaderApi);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  it('GETs subscriptions', () => {
    api.subscriptions().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ subscriptions: [] });
  });

  it('POSTs a subscribe URL', () => {
    api.subscribe('https://example.com/feed').subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ url: 'https://example.com/feed' });
    testRequest.flush({ subscription: {} });
  });

  it('includes tagIds in the subscribe body only when tags are selected', () => {
    api.subscribe('https://example.com/feed', undefined, [2, 5]).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(testRequest.request.body).toEqual({ url: 'https://example.com/feed', tagIds: [2, 5] });
    testRequest.flush({ subscription: {} });

    // An empty selection stays byte-compatible with the tag-less body.
    api.subscribe('https://example.com/feed', undefined, []).subscribe();
    expect(ctrl.expectOne('https://api.test/api/subscriptions').request.body).toEqual({
      url: 'https://example.com/feed',
    });
  });

  it('detects ReaderApi dropping a supplied WordPress title from the subscribe body', () => {
    api
      .subscribe(
        'https://wp.example/wp-json/wp/v2/posts',
        'wp-json',
        undefined,
        'WordPress Example',
      )
      .subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(testRequest.request.body).toEqual({
      url: 'https://wp.example/wp-json/wp/v2/posts',
      format: 'wp-json',
      title: 'WordPress Example',
    });
    testRequest.flush({ subscription: {} });
  });

  it('GETs a single entry by id', () => {
    api.entry(514).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/514');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ entry: {} });
  });

  it('GETs entries with only the set filters, cursor last', () => {
    api.entries({ view: 'unread', subscription: 7 }, 'CUR').subscribe();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(testRequest.request.params.get('view')).toBe('unread');
    expect(testRequest.request.params.get('subscription')).toBe('7');
    expect(testRequest.request.params.get('tag')).toBeNull();
    expect(testRequest.request.params.get('cursor')).toBe('CUR');
    testRequest.flush({ entries: [], nextCursor: null });
  });

  // #91: the STRATO host is slow, so the client asks for the biggest page the
  // backend allows rather than falling back to its smaller default.
  it('asks for a full page on both the first request and a paged one', () => {
    api.entries({ view: 'all' }).subscribe();
    api.entries({ view: 'all' }, 'CUR').subscribe();
    const reqs = ctrl.match((request) => request.url === 'https://api.test/api/entries');
    expect(reqs.map((testRequest) => testRequest.request.params.get('limit'))).toEqual([
      String(PAGE_SIZE),
      String(PAGE_SIZE),
    ]);
    for (const testRequest of reqs) testRequest.flush({ entries: [], nextCursor: null });
  });

  it('routes a query with q to the search endpoint', () => {
    api.entries({ view: 'all', q: 'testing' }).subscribe();
    const testRequest = ctrl.expectOne(
      (request) => request.url === 'https://api.test/api/entries/search',
    );
    expect(testRequest.request.params.get('q')).toBe('testing');
    expect(testRequest.request.params.get('limit')).toBe(String(PAGE_SIZE));
    expect(testRequest.request.params.has('view')).toBe(false);
    expect(testRequest.request.params.has('tag')).toBe(false);
    expect(testRequest.request.params.has('subscription')).toBe(false);
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('forwards a cursor on the search path', () => {
    api.entries({ view: 'all', q: 'testing' }, 'SEARCH_CUR').subscribe();
    const testRequest = ctrl.expectOne(
      (request) => request.url === 'https://api.test/api/entries/search',
    );
    expect(testRequest.request.params.get('q')).toBe('testing');
    expect(testRequest.request.params.get('cursor')).toBe('SEARCH_CUR');
    expect(testRequest.request.params.get('limit')).toBe(String(PAGE_SIZE));
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('forwards the unread refinement on the search path', () => {
    api.entries({ view: 'all', q: 'testing', unread: true }).subscribe();

    const testRequest = ctrl.expectOne(
      (request) => request.url === 'https://api.test/api/entries/search',
    );
    expect(testRequest.request.params.get('unread')).toBe('1');
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('still routes a query without q to the main list', () => {
    api.entries({ view: 'favorites' }).subscribe();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(testRequest.request.params.get('view')).toBe('favorites');
    expect(testRequest.request.params.has('q')).toBe(false);
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('PATCHes entry state', () => {
    api.updateState(3, { isFavorite: true }).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/3/state');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ isFavorite: true });
    testRequest.flush({
      state: {
        entryId: 3,
        isHidden: false,
        isFavorite: true,
        isKept: false,
        hiddenAt: null,
        isViewed: false,
        viewedAt: null,
      },
    });
  });

  it('POSTs mark-read with scope/until/id', () => {
    api.markRead('feed', '2026-01-01T00:00:00Z', 9).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/mark-read');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({
      scope: 'feed',
      until: '2026-01-01T00:00:00Z',
      id: 9,
    });
    testRequest.flush(null);
  });

  it('POSTs search mark-read with q/until', () => {
    api.markSearchRead('climate ', '2026-01-01T00:00:00Z').subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/search/mark-read');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ q: 'climate ', until: '2026-01-01T00:00:00Z' });
    testRequest.flush(null);
  });

  it('POSTs for-you mark-read with until alone', () => {
    api.markForYouRead('2026-01-01T00:00:00Z').subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/entries/for-you/mark-read');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ until: '2026-01-01T00:00:00Z' });
    testRequest.flush(null);
  });

  it('asks the ranked feed for unread picks with a query flag', () => {
    api.entries({ view: 'for-you', unread: true }).subscribe();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(testRequest.request.params.get('view')).toBe('for-you');
    expect(testRequest.request.params.get('unread')).toBe('1');
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('reads the combined saved-search list from its own endpoint', () => {
    api.entries({ view: 'saved-searches' }).subscribe();
    const testRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/entries/saved-searches'),
    );
    expect(testRequest.request.params.get('unread')).toBeNull();
    expect(testRequest.request.params.get('view')).toBeNull();
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('sends unread=1 when the list is filtered', () => {
    api.entries({ view: 'saved-searches', unread: true }).subscribe();
    const testRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/entries/saved-searches'),
    );
    expect(testRequest.request.params.get('unread')).toBe('1');
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('fetches a single saved search by id from its membership endpoint', () => {
    api.entries({ view: 'all', savedSearchId: 42 }).subscribe();
    const testRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/entries/saved-searches/42'),
    );
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('asks every list endpoint for oldest first only when the query says so', () => {
    api.entries({ view: 'all', order: 'oldest' }).subscribe();
    api.entries({ view: 'all', q: 'testing', order: 'oldest' }).subscribe();
    api.entries({ view: 'saved-searches', order: 'oldest' }).subscribe();
    api.entries({ view: 'all', savedSearchId: 42, order: 'oldest' }).subscribe();
    api.entries({ view: 'all', order: 'newest' }).subscribe();

    const requests = ctrl.match((request) =>
      request.url.startsWith('https://api.test/api/entries'),
    );
    expect(requests.map((testRequest) => testRequest.request.params.get('order'))).toEqual([
      'asc',
      'asc',
      'asc',
      'asc',
      null,
    ]);
    for (const request of requests) request.flush({ entries: [], nextCursor: null });
  });

  it('marks the combined saved-search list read with only a watermark', () => {
    api.markSavedSearchesRead('2026-09-01T10:00:00.000Z').subscribe();
    const testRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/entries/saved-searches/mark-read'),
    );
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ until: '2026-09-01T10:00:00.000Z' });
    testRequest.flush(null);
  });

  it('posts the id list to mark-read-batch', () => {
    api.markEntriesRead([11, 22, 33]).subscribe();
    const testRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/entries/mark-read-batch'),
    );
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ ids: [11, 22, 33] });
    testRequest.flush(null);
  });

  it('sends no unread flag for a feed that shows everything', () => {
    api.entries({ view: 'all' }).subscribe();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/entries');
    expect(testRequest.request.params.has('unread')).toBe(false);
    testRequest.flush({ entries: [], nextCursor: null });
  });

  it('POSTs refresh', () => {
    api.refresh().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/refresh');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.params.has('feedId')).toBe(false);
    testRequest.flush(refreshReport());
  });

  it('scopes refresh to a single feed when given a feedId', () => {
    api.refresh({ feedId: 42 }).subscribe();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.params.get('feedId')).toBe('42');
    expect(testRequest.request.params.has('tag')).toBe(false);
    testRequest.flush(refreshReport({ progress: { done: 1, total: 1 }, fetched: 1 }));
  });

  it('scopes refresh to a tag when given a tagId', () => {
    api.refresh({ tagId: 3 }).subscribe();
    const testRequest = ctrl.expectOne((request) => request.url === 'https://api.test/api/refresh');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.params.get('tag')).toBe('3');
    expect(testRequest.request.params.has('feedId')).toBe(false);
    testRequest.flush(refreshReport({ progress: { done: 1, total: 1 }, notModified: 1 }));
  });

  describe('ReaderApi management methods', () => {
    it('PATCHes a subscription update', () => {
      api.updateSubscription(7, { customTitle: 'My name', tagIds: [1, 2] }).subscribe();
      const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/7');
      expect(testRequest.request.method).toBe('PATCH');
      expect(testRequest.request.body).toEqual({ customTitle: 'My name', tagIds: [1, 2] });
      testRequest.flush({ subscription: {} });
    });

    it('DELETEs a subscription', () => {
      api.deleteSubscription(7).subscribe();
      const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/7');
      expect(testRequest.request.method).toBe('DELETE');
      testRequest.flush(null);
    });

    it('PATCHes a feed move onto the move-to-tag endpoint', () => {
      api.moveFeedToTag(7, { fromTagId: 1, toTagId: 2, position: 3 }).subscribe();
      const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/7/move-to-tag');
      expect(testRequest.request.method).toBe('PATCH');
      expect(testRequest.request.body).toEqual({ fromTagId: 1, toTagId: 2, position: 3 });
      testRequest.flush({ subscription: {} });
    });

    it('GETs all tags', () => {
      api.tags().subscribe();
      const testRequest = ctrl.expectOne('https://api.test/api/tags');
      expect(testRequest.request.method).toBe('GET');
      testRequest.flush({ tags: [] });
    });

    it('POSTs a new tag', () => {
      api.createTag({ name: 'Tech', color: '#3f8676', icon: 'code' }).subscribe();
      const testRequest = ctrl.expectOne('https://api.test/api/tags');
      expect(testRequest.request.method).toBe('POST');
      expect(testRequest.request.body).toEqual({ name: 'Tech', color: '#3f8676', icon: 'code' });
      testRequest.flush({ tag: {} });
    });

    it('PATCHes a tag', () => {
      api.updateTag(3, { name: 'Tech', color: null, icon: null }).subscribe();
      const testRequest = ctrl.expectOne('https://api.test/api/tags/3');
      expect(testRequest.request.method).toBe('PATCH');
      testRequest.flush({ tag: {} });
    });

    it('DELETEs a tag', () => {
      api.deleteTag(3).subscribe();
      const testRequest = ctrl.expectOne('https://api.test/api/tags/3');
      expect(testRequest.request.method).toBe('DELETE');
      testRequest.flush(null);
    });
  });

  it('GETs reader content for an entry', () => {
    let received: ReaderContent | undefined;
    api.readerContent(42).subscribe((content) => (received = content));

    const testRequest = ctrl.expectOne((request) => request.url.endsWith('/api/entries/42/reader'));
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({
      status: 'failed',
      url: null,
      reason: 'no_url',
      detail: null,
      originalHero: null,
    } satisfies ReaderContent);

    expect(received?.status).toBe('failed');
  });

  it('GETs comments for an entry', () => {
    let received: unknown;
    api.comments(7).subscribe((comments) => (received = comments));

    const testRequest = ctrl.expectOne('https://api.test/api/entries/7/comments');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ status: 'failed' });

    expect(received).toEqual({ status: 'failed' });
  });

  it('POSTs a feed preview request', () => {
    api.previewFeed('https://f').subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/feeds/preview');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ url: 'https://f' });
    testRequest.flush({
      feed: {
        title: null,
        itemCount: 0,
        content: 'title-only',
        hasImages: false,
        items: [],
      },
    });
  });

  it('POSTs to start a recommendation run', () => {
    api.startRecommendations().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/recommendations/runs');
    expect(testRequest.request.method).toBe('POST');
    testRequest.flush({
      status: 'pending',
      batchesTotal: null,
      batchesDone: 0,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
  });

  it('POSTs to resume a recommendation run', () => {
    api.resumeRecommendations().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/recommendations/runs/resume');
    expect(testRequest.request.method).toBe('POST');
    testRequest.flush({
      status: 'running',
      batchesTotal: 3,
      batchesDone: 1,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
  });

  it('POSTs to tick a recommendation run', () => {
    api.tickRecommendations().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/recommendations/runs/tick');
    expect(testRequest.request.method).toBe('POST');
    testRequest.flush({
      status: 'running',
      batchesTotal: 3,
      batchesDone: 1,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
  });

  it('GETs the current recommendation run', () => {
    api.currentRecommendations().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/recommendations/runs/current');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({
      status: 'none',
      batchesTotal: null,
      batchesDone: 0,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
  });
});
