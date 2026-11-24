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

    const testRequest = ctrl.expectOne('https://api.test/api/account/backup');
    expect(testRequest.request.method).toBe('GET');
    expect(testRequest.request.responseType).toBe('blob');

    const blob = new Blob(['gzipped'], { type: 'application/gzip' });
    testRequest.flush(blob);

    expect(received).toBe(blob);
  });

  it('POSTs a gzip body to preview a restore', () => {
    const backup = new Blob(['gzipped'], { type: 'application/gzip' });
    let received: RestorePreview | undefined;
    api.previewAccountRestore(backup).subscribe((preview) => (received = preview));

    const testRequest = ctrl.expectOne('https://api.test/api/account/restore/preview');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toBe(backup);
    expect(testRequest.request.headers.get('Content-Type')).toBe('application/gzip');

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
    testRequest.flush(preview);

    expect(received).toEqual(preview);
  });

  it('POSTs a gzip body to start a restore', () => {
    const foundation = new Blob(['gzipped'], { type: 'application/gzip' });
    let received: RestoreResult | undefined;
    api.startAccountRestore(foundation).subscribe((result) => (received = result));

    const testRequest = ctrl.expectOne(
      'https://api.test/api/account/restore/start?confirm=REPLACE',
    );
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toBe(foundation);
    expect(testRequest.request.headers.get('Content-Type')).toBe('application/gzip');

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
    testRequest.flush(result);

    expect(received).toEqual(result);
  });

  it('POSTs a gzip body to restore one entry part', () => {
    const part = new Blob(['gzipped'], { type: 'application/gzip' });
    let received: RestoreResult | undefined;
    api.restoreEntryPart(part).subscribe((result) => (received = result));

    const testRequest = ctrl.expectOne('https://api.test/api/account/restore/entries');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toBe(part);
    expect(testRequest.request.headers.get('Content-Type')).toBe('application/gzip');

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
    testRequest.flush(result);

    expect(received).toEqual(result);
  });

  it('GETs OPML export as text', () => {
    api.exportOpml().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/opml/export');
    expect(testRequest.request.method).toBe('GET');
    expect(testRequest.request.responseType).toBe('text');
    testRequest.flush('<opml/>');
  });

  it('POSTs the OPML preview request as JSON', () => {
    api.previewOpml('<opml/>', 'P').subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/opml/preview');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ opml: '<opml/>', parentTagName: 'P' });
    testRequest.flush({
      freeSlots: 1,
      root: { name: null, dropped: false, destination: [], feeds: [], folders: [] },
    });
  });

  it('POSTs the OPML import request as JSON', () => {
    const request = {
      opml: '<opml/>',
      parentTagName: null,
      feedUrls: ['https://a.test/feed'],
      tagIdsByFeedUrl: { 'https://a.test/feed': [3] },
    };
    api.importOpml(request).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/opml/import');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual(request);
    expect(testRequest.request.headers.has('Content-Type')).toBe(false);
    testRequest.flush({ imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0 });
  });

  it('GETs the account backup as a blob, observing the full response for its headers', () => {
    let filename: string | null = null;
    api.downloadAccountBackup().subscribe((response) => {
      filename = response.headers.get('Content-Disposition');
    });
    const testRequest = ctrl.expectOne('https://api.test/api/account/backup');
    expect(testRequest.request.method).toBe('GET');
    expect(testRequest.request.responseType).toBe('blob');
    testRequest.flush(new Blob(['gzipped']), {
      headers: { 'Content-Disposition': 'attachment; filename="account.json.gz"' },
    });
    expect(filename).toBe('attachment; filename="account.json.gz"');
  });

  it('GETs the reading activity without a time zone', () => {
    api.readingActivity().subscribe();

    const testRequest = ctrl.expectOne('https://api.test/api/reading/activity');
    expect(testRequest.request.method).toBe('GET');
    expect(testRequest.request.params.keys()).toEqual([]);
    testRequest.flush({
      days: [],
      total: 0,
      arrivedDailyAverage: null,
      topFeedsByRead: [],
      topFeedsByArrivals: [],
      viewedCount: 0,
      favoritesCount: 0,
    });
  });

  it('GETs the debug log', () => {
    api.debugLog().subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/recommendations/runs/debug-log');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ run: null, entries: [] });
  });

  it('GETs the debug log of one run by kind and id', () => {
    api.debugLog({ kind: 'story', id: 7 }).subscribe();
    const testRequest = ctrl.expectOne(
      'https://api.test/api/recommendations/runs/debug-log?kind=story&run=7',
    );
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({ run: null, runs: [], entries: [] });
  });

  it('GETs one debug log entry', () => {
    api.debugLogEntry(7).subscribe();
    const testRequest = ctrl.expectOne('https://api.test/api/recommendations/runs/debug-log/7');
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({
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

  it('GETs the run history overview with the timezone, and the kind only when set', () => {
    api.runHistory('Europe/Berlin').subscribe();
    const unfiltered = ctrl.expectOne(
      (request) => request.url === 'https://api.test/api/recommendations/runs/history',
    );
    expect(unfiltered.request.params.get('tz')).toBe('Europe/Berlin');
    expect(unfiltered.request.params.has('kind')).toBe(false);
    unfiltered.flush({ totalCostNanoCredits: null, months: [], latest: null });

    api.runHistory('Europe/Berlin', 'story').subscribe();
    const filtered = ctrl.expectOne(
      (request) => request.url === 'https://api.test/api/recommendations/runs/history',
    );
    expect(filtered.request.params.get('kind')).toBe('story');
    filtered.flush({ totalCostNanoCredits: null, months: [], latest: null });
  });

  it('GETs one history month with the kind and the opaque cursor as strings', () => {
    api.runHistoryMonth('2026-08', 'UTC', { kind: 'profile', before: 'abc.def' }).subscribe();
    const testRequest = ctrl.expectOne(
      (request) => request.url === 'https://api.test/api/recommendations/runs/history/2026-08',
    );
    expect(testRequest.request.params.get('tz')).toBe('UTC');
    expect(testRequest.request.params.get('kind')).toBe('profile');
    expect(testRequest.request.params.get('before')).toBe('abc.def');
    testRequest.flush({ month: '2026-08', runs: [], nextCursor: null });
  });
});
