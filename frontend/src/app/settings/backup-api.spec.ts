import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { ReaderApi } from '../reader/reader-api';
import { RestorePreview, RestoreResult } from '../reader/models';

describe('ReaderApi account backup/restore', () => {
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

  it('GETs the account backup as a blob', () => {
    let received: Blob | null | undefined;
    api.downloadAccountBackup().subscribe((response) => (received = response.body));

    const req = ctrl.expectOne('https://api.test/api/account/backup');
    expect(req.request.method).toBe('GET');
    expect(req.request.responseType).toBe('blob');

    const blob = new Blob(['gzipped'], { type: 'application/gzip' });
    req.flush(blob);

    expect(received).toBe(blob);
  });

  it('POSTs a gzip body to preview a restore', () => {
    const backup = new Blob(['gzipped'], { type: 'application/gzip' });
    let received: RestorePreview | undefined;
    api.previewAccountRestore(backup).subscribe((p) => (received = p));

    const req = ctrl.expectOne('https://api.test/api/account/restore/preview');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe(backup);
    expect(req.request.headers.get('Content-Type')).toBe('application/gzip');

    const preview: RestorePreview = {
      backup: {
        backupId: 'a1b2c3',
        parts: 3,
        createdAt: '2026-08-17T10:00:00Z',
        sourceUrl: null,
        sourceEmail: null,
      },
      toLoad: {
        tags: 1,
        savedSearches: 1,
        feeds: 2,
        subscriptions: 2,
        entries: 10,
        entryStates: 10,
      },
      toDelete: { tags: 0, subscriptions: 0, entryStates: 0, recommendationRuns: 0 },
    };
    req.flush(preview);

    expect(received).toEqual(preview);
  });

  it('POSTs a gzip body to start a restore', () => {
    const foundation = new Blob(['gzipped'], { type: 'application/gzip' });
    let received: RestoreResult | undefined;
    api.startAccountRestore(foundation).subscribe((r) => (received = r));

    const req = ctrl.expectOne('https://api.test/api/account/restore/start?confirm=REPLACE');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe(foundation);
    expect(req.request.headers.get('Content-Type')).toBe('application/gzip');

    const result: RestoreResult = {
      loaded: {
        tags: 1,
        savedSearches: 1,
        feeds: 2,
        subscriptions: 2,
        entries: 0,
        entryStates: 0,
      },
    };
    req.flush(result);

    expect(received).toEqual(result);
  });

  it('POSTs a gzip body to restore one entry part', () => {
    const part = new Blob(['gzipped'], { type: 'application/gzip' });
    let received: RestoreResult | undefined;
    api.restoreEntryPart(part).subscribe((r) => (received = r));

    const req = ctrl.expectOne('https://api.test/api/account/restore/entries');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe(part);
    expect(req.request.headers.get('Content-Type')).toBe('application/gzip');

    const result: RestoreResult = {
      loaded: {
        tags: 0,
        savedSearches: 0,
        feeds: 0,
        subscriptions: 0,
        entries: 10,
        entryStates: 10,
      },
    };
    req.flush(result);

    expect(received).toEqual(result);
  });
});
