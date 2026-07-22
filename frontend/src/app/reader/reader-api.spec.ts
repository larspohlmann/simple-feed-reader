import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { ReaderApi } from './reader-api';

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

  it('GETs entries with only the set filters, cursor last', () => {
    api.entries({ view: 'unread', subscription: 7 }, 'CUR').subscribe();
    const req = ctrl.expectOne((r) => r.url === 'https://api.test/api/entries');
    expect(req.request.params.get('view')).toBe('unread');
    expect(req.request.params.get('subscription')).toBe('7');
    expect(req.request.params.get('tag')).toBeNull();
    expect(req.request.params.get('cursor')).toBe('CUR');
    req.flush({ entries: [], nextCursor: null });
  });

  it('PATCHes entry state', () => {
    api.updateState(3, { isFavorite: true }).subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/3/state');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ isFavorite: true });
    req.flush({
      state: { entryId: 3, isRead: false, isFavorite: true, isKept: false, readAt: null },
    });
  });

  it('POSTs mark-read with scope/until/id', () => {
    api.markRead('feed', '2026-01-01T00:00:00Z', 9).subscribe();
    const req = ctrl.expectOne('https://api.test/api/entries/mark-read');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ scope: 'feed', until: '2026-01-01T00:00:00Z', id: 9 });
    req.flush(null);
  });

  it('POSTs refresh', () => {
    api.refresh().subscribe();
    const req = ctrl.expectOne('https://api.test/api/refresh');
    expect(req.request.method).toBe('POST');
    req.flush({
      status: 'completed',
      total: 0,
      fetched: 0,
      notModified: 0,
      failed: 0,
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
  });
});
