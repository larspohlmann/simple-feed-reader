import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { ReaderApi } from './reader-api';
import { PAGE_SIZE } from './paging';
import { ReaderContent } from './models';

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
    const req = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(req.request.method).toBe('GET');
    req.flush({ subscriptions: [] });
  });

  it('POSTs a subscribe URL', () => {
    api.subscribe('https://example.com/feed').subscribe();
    const req = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ url: 'https://example.com/feed' });
    req.flush({ subscription: {} });
  });

  it('includes tagIds in the subscribe body only when tags are selected', () => {
    api.subscribe('https://example.com/feed', undefined, [2, 5]).subscribe();
    const req = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(req.request.body).toEqual({ url: 'https://example.com/feed', tagIds: [2, 5] });
    req.flush({ subscription: {} });

    // An empty selection stays byte-compatible with the tag-less body.
    api.subscribe('https://example.com/feed', undefined, []).subscribe();
    expect(ctrl.expectOne('https://api.test/api/subscriptions').request.body).toEqual({
      url: 'https://example.com/feed',
    });
  });

  it('GETs a single entry by id', () => {
    api.entry(514).subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/514');
    expect(req.request.method).toBe('GET');
    req.flush({ entry: {} });
  });

  it('GETs entries with only the set filters, cursor last', () => {
    api.entries({ view: 'unread', subscription: 7 }, 'CUR').subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
    expect(req.request.params.get('view')).toBe('unread');
    expect(req.request.params.get('subscription')).toBe('7');
    expect(req.request.params.get('tag')).toBeNull();
    expect(req.request.params.get('cursor')).toBe('CUR');
    req.flush({ entries: [], nextCursor: null });
  });

  // #91: the STRATO host is slow, so the client asks for the biggest page the
  // backend allows rather than falling back to its smaller default.
  it('asks for a full page on both the first request and a paged one', () => {
    api.entries({ view: 'all' }).subscribe();
    api.entries({ view: 'all' }, 'CUR').subscribe();
    const reqs = ctrl.match((r) => r.url === 'https://api.test/api/entries');
    expect(reqs.map((r) => r.request.params.get('limit'))).toEqual([
      String(PAGE_SIZE),
      String(PAGE_SIZE),
    ]);
    for (const r of reqs) r.flush({ entries: [], nextCursor: null });
  });

  it('routes a query with q to the search endpoint', () => {
    api.entries({ view: 'all', q: 'testing' }).subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries/search');
    expect(req.request.params.get('q')).toBe('testing');
    expect(req.request.params.get('limit')).toBe(String(PAGE_SIZE));
    expect(req.request.params.has('view')).toBe(false);
    expect(req.request.params.has('tag')).toBe(false);
    expect(req.request.params.has('subscription')).toBe(false);
    req.flush({ entries: [], nextCursor: null });
  });

  it('forwards a cursor on the search path', () => {
    api.entries({ view: 'all', q: 'testing' }, 'SEARCH_CUR').subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries/search');
    expect(req.request.params.get('q')).toBe('testing');
    expect(req.request.params.get('cursor')).toBe('SEARCH_CUR');
    expect(req.request.params.get('limit')).toBe(String(PAGE_SIZE));
    req.flush({ entries: [], nextCursor: null });
  });

  it('still routes a query without q to the main list', () => {
    api.entries({ view: 'favorites' }).subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
    expect(req.request.params.get('view')).toBe('favorites');
    expect(req.request.params.has('q')).toBe(false);
    req.flush({ entries: [], nextCursor: null });
  });

  it('PATCHes entry state', () => {
    api.updateState(3, { isFavorite: true }).subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/3/state');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ isFavorite: true });
    req.flush({
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
    const req = ctrl.expectOne('https://api.test/api/entries/mark-read');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ scope: 'feed', until: '2026-01-01T00:00:00Z', id: 9 });
    req.flush(null);
  });

  it('POSTs search mark-read with q/until', () => {
    api.markSearchRead('climate ', '2026-01-01T00:00:00Z').subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/search/mark-read');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ q: 'climate ', until: '2026-01-01T00:00:00Z' });
    req.flush(null);
  });

  it('POSTs for-you mark-read with until alone', () => {
    api.markForYouRead('2026-01-01T00:00:00Z').subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/for-you/mark-read');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ until: '2026-01-01T00:00:00Z' });
    req.flush(null);
  });

  it('asks the ranked feed for unread picks with a query flag', () => {
    api.entries({ view: 'for-you', unread: true }).subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
    expect(req.request.params.get('view')).toBe('for-you');
    expect(req.request.params.get('unread')).toBe('1');
    req.flush({ entries: [], nextCursor: null });
  });

  it('sends no unread flag for a feed that shows everything', () => {
    api.entries({ view: 'all' }).subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
    expect(req.request.params.has('unread')).toBe(false);
    req.flush({ entries: [], nextCursor: null });
  });

  it('POSTs refresh', () => {
    api.refresh().subscribe();
    const req = ctrl.expectOne('https://api.test/api/refresh');
    expect(req.request.method).toBe('POST');
    expect(req.request.params.has('feedId')).toBe(false);
    req.flush({
      status: 'completed',
      progress: { done: 0, total: 0 },
      fetched: 0,
      notModified: 0,
      failed: 0,
      throttled: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
    });
  });

  it('scopes refresh to a single feed when given a feedId', () => {
    api.refresh({ feedId: 42 }).subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(req.request.method).toBe('POST');
    expect(req.request.params.get('feedId')).toBe('42');
    expect(req.request.params.has('tag')).toBe(false);
    req.flush({
      status: 'completed',
      progress: { done: 1, total: 1 },
      fetched: 1,
      notModified: 0,
      failed: 0,
      throttled: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
    });
  });

  it('scopes refresh to a tag when given a tagId', () => {
    api.refresh({ tagId: 3 }).subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/refresh');
    expect(req.request.method).toBe('POST');
    expect(req.request.params.get('tag')).toBe('3');
    expect(req.request.params.has('feedId')).toBe(false);
    req.flush({
      status: 'completed',
      progress: { done: 1, total: 1 },
      fetched: 0,
      notModified: 1,
      failed: 0,
      throttled: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
    });
  });

  describe('ReaderApi management methods', () => {
    it('PATCHes a subscription update', () => {
      api.updateSubscription(7, { customTitle: 'My name', tagIds: [1, 2] }).subscribe();
      const req = ctrl.expectOne('https://api.test/api/subscriptions/7');
      expect(req.request.method).toBe('PATCH');
      expect(req.request.body).toEqual({ customTitle: 'My name', tagIds: [1, 2] });
      req.flush({ subscription: {} });
    });

    it('DELETEs a subscription', () => {
      api.deleteSubscription(7).subscribe();
      const req = ctrl.expectOne('https://api.test/api/subscriptions/7');
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('GETs all tags', () => {
      api.tags().subscribe();
      const req = ctrl.expectOne('https://api.test/api/tags');
      expect(req.request.method).toBe('GET');
      req.flush({ tags: [] });
    });

    it('POSTs a new tag', () => {
      api.createTag({ name: 'Tech', color: '#3f8676', icon: 'code' }).subscribe();
      const req = ctrl.expectOne('https://api.test/api/tags');
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ name: 'Tech', color: '#3f8676', icon: 'code' });
      req.flush({ tag: {} });
    });

    it('PATCHes a tag', () => {
      api.updateTag(3, { name: 'Tech', color: null, icon: null }).subscribe();
      const req = ctrl.expectOne('https://api.test/api/tags/3');
      expect(req.request.method).toBe('PATCH');
      req.flush({ tag: {} });
    });

    it('DELETEs a tag', () => {
      api.deleteTag(3).subscribe();
      const req = ctrl.expectOne('https://api.test/api/tags/3');
      expect(req.request.method).toBe('DELETE');
      req.flush(null);
    });

    it('GETs OPML export as text', () => {
      api.exportOpml().subscribe();
      const req = ctrl.expectOne('https://api.test/api/opml/export');
      expect(req.request.method).toBe('GET');
      expect(req.request.responseType).toBe('text');
      req.flush('<opml/>');
    });

    it('POSTs OPML import as a raw body', () => {
      api.importOpml('<opml/>').subscribe();
      const req = ctrl.expectOne('https://api.test/api/opml/import');
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toBe('<opml/>');
      req.flush({ imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0 });
    });

    it('GETs the account backup as a blob, observing the full response for its headers', () => {
      let filename: string | null = null;
      api.downloadAccountBackup().subscribe((response) => {
        filename = response.headers.get('Content-Disposition');
      });
      const req = ctrl.expectOne('https://api.test/api/account/backup');
      expect(req.request.method).toBe('GET');
      expect(req.request.responseType).toBe('blob');
      req.flush(new Blob(['gzipped']), {
        headers: { 'Content-Disposition': 'attachment; filename="account.json.gz"' },
      });
      expect(filename).toBe('attachment; filename="account.json.gz"');
    });
  });

  it('GETs reader content for an entry', () => {
    let received: ReaderContent | undefined;
    api.readerContent(42).subscribe((c) => (received = c));

    const req = ctrl.expectOne((r) => r.url.endsWith('/api/entries/42/reader'));
    expect(req.request.method).toBe('GET');
    req.flush({
      status: 'failed',
      url: null,
      reason: 'no_url',
      originalHero: null,
    } satisfies ReaderContent);

    expect(received?.status).toBe('failed');
  });

  it('POSTs a feed preview request', () => {
    api.previewFeed('https://f').subscribe();
    const req = ctrl.expectOne('https://api.test/api/feeds/preview');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ url: 'https://f' });
    req.flush({
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
    const req = ctrl.expectOne('https://api.test/api/recommendations/runs');
    expect(req.request.method).toBe('POST');
    req.flush({
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
    const req = ctrl.expectOne('https://api.test/api/recommendations/runs/resume');
    expect(req.request.method).toBe('POST');
    req.flush({
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
    const req = ctrl.expectOne('https://api.test/api/recommendations/runs/tick');
    expect(req.request.method).toBe('POST');
    req.flush({
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
    const req = ctrl.expectOne('https://api.test/api/recommendations/runs/current');
    expect(req.request.method).toBe('GET');
    req.flush({
      status: 'none',
      batchesTotal: null,
      batchesDone: 0,
      error: null,
      background: false,
      streamedChars: 0,
      forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
    });
  });

  it('GETs the debug log', () => {
    api.debugLog().subscribe();
    const req = ctrl.expectOne('https://api.test/api/recommendations/runs/debug-log');
    expect(req.request.method).toBe('GET');
    req.flush({ run: null, entries: [] });
  });

  it('GETs one debug log entry', () => {
    api.debugLogEntry(7).subscribe();
    const req = ctrl.expectOne('https://api.test/api/recommendations/runs/debug-log/7');
    expect(req.request.method).toBe('GET');
    req.flush({
      id: 7,
      phase: 'batch',
      batchNumber: 1,
      attempt: 1,
      verdict: 'usable',
      requestBody: '{}',
      responseText: '{}',
      finishReason: 'stop',
    });
  });
});
