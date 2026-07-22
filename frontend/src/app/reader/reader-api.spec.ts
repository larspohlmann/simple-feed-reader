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
});
