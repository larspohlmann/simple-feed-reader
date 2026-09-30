import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { SettingsApi } from './settings-api';
import { RestorePreview, RestoreResult } from './settings.models';

describe('SettingsApi', () => {
  let api: SettingsApi;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    api = TestBed.inject(SettingsApi);
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
