// src/app/settings/backup-api.spec.ts
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
      backup: { createdAt: '2026-08-17T10:00:00Z', sourceUrl: null, sourceEmail: null },
      toLoad: { tags: 1, feeds: 2, subscriptions: 2, entries: 10, entryStates: 10 },
      toDelete: { tags: 0, subscriptions: 0, entryStates: 0, recommendationRuns: 0 },
    };
    req.flush(preview);

    expect(received).toEqual(preview);
  });

  it('POSTs a gzip body to confirm a restore', () => {
    const backup = new Blob(['gzipped'], { type: 'application/gzip' });
    let received: RestoreResult | undefined;
    api.restoreAccount(backup).subscribe((r) => (received = r));

    const req = ctrl.expectOne('https://api.test/api/account/restore?confirm=REPLACE');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toBe(backup);
    expect(req.request.headers.get('Content-Type')).toBe('application/gzip');

    const result: RestoreResult = {
      loaded: { tags: 1, feeds: 2, subscriptions: 2, entries: 10, entryStates: 10 },
    };
    req.flush(result);

    expect(received).toEqual(result);
  });
});
