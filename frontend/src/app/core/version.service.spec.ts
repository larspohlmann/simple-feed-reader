import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from './api';
import { VersionService } from './version.service';

describe('VersionService', () => {
  let service: VersionService;
  let ctrl: HttpTestingController;

  const release = { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '2026-07-27T10:04:11Z' };

  let visibility: DocumentVisibilityState = 'visible';

  const setTab = (state: DocumentVisibilityState): void => {
    visibility = state;
    document.dispatchEvent(new Event('visibilitychange'));
  };
  const showTab = (): void => setTab('visible');
  const hideTab = (): void => setTab('hidden');

  beforeEach(() => {
    jest.useFakeTimers();
    jest.setSystemTime(new Date('2026-10-31T10:00:00Z'));
    visibility = 'visible';
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => visibility,
    });
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    service = TestBed.inject(VersionService);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => {
    try {
      ctrl.verify();
    } finally {
      jest.useRealTimers();
      Reflect.deleteProperty(document, 'visibilityState');
    }
  });

  it('exposes the build the API reports', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    expect(service.apiVersion()).toEqual(release);
    expect(service.unavailable()).toBe(false);
  });

  it('marks the API version unavailable when the call fails', () => {
    service.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush(null, { status: 503, statusText: 'Service Unavailable' });

    expect(service.apiVersion()).toBeNull();
    expect(service.unavailable()).toBe(true);
  });

  it('asks the server to check upstream again and takes its answer', () => {
    let done = false;
    service.checkNow().subscribe(() => (done = true));
    const request = ctrl.expectOne('https://api.test/api/admin/version/check');
    expect(request.request.method).toBe('POST');
    request.flush({
      ...release,
      latest: { version: 'v0.6.0', notesUrl: 'https://example.test/notes' },
      updateAvailable: true,
    });

    expect(done).toBe(true);
    expect(service.updateAvailable()).toBe(true);
    expect(service.latest()?.version).toBe('v0.6.0');
  });

  it('keeps what it knew when the check fails', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush({ ...release, updateAvailable: true });

    let failed = false;
    service.checkNow().subscribe({ error: () => (failed = true) });
    ctrl
      .expectOne('https://api.test/api/admin/version/check')
      .flush(null, { status: 502, statusText: 'Bad Gateway' });

    expect(failed).toBe(true);
    expect(service.apiVersion()).toEqual({ ...release, updateAvailable: true });
    expect(service.updateAvailable()).toBe(true);
  });

  it('re-checks on every load, because the server can be redeployed under a loaded bundle', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    const newer = { ...release, version: 'v0.5.0-dev.4' };
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(newer);

    expect(service.apiVersion()).toEqual(newer);
  });

  it('clears a previous failure once the endpoint answers again', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(null, { status: 503, statusText: 'down' });
    expect(service.unavailable()).toBe(true);

    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    expect(service.unavailable()).toBe(false);
  });

  const latest = { version: 'v0.6.0', notesUrl: 'https://github.test/releases/tag/v0.6.0' };

  it('surfaces an available update from the response', () => {
    service.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush({ ...release, updateAvailable: true, latest });

    expect(service.updateAvailable()).toBe(true);
    expect(service.latest()).toEqual(latest);
  });

  it('reports no update when the server signals none', () => {
    service.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush({ ...release, updateAvailable: false, latest: null });

    expect(service.updateAvailable()).toBe(false);
    expect(service.latest()).toBeNull();
  });

  it('treats a response without the update fields as no update', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    expect(service.updateAvailable()).toBe(false);
    expect(service.latest()).toBeNull();
  });

  it('clears a stale update signal once a later check reports none', () => {
    service.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush({ ...release, updateAvailable: true, latest });
    expect(service.updateAvailable()).toBe(true);

    service.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush({ ...release, updateAvailable: false, latest: null });

    expect(service.updateAvailable()).toBe(false);
    expect(service.latest()).toBeNull();
  });

  it('drops any update signal when the call fails', () => {
    service.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush({ ...release, updateAvailable: true, latest });
    expect(service.updateAvailable()).toBe(true);

    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(null, { status: 503, statusText: 'down' });

    expect(service.updateAvailable()).toBe(false);
    expect(service.latest()).toBeNull();
  });

  it('checks again when the tab comes back after half an hour', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 30 * 60_000);
    showTab();

    ctrl.expectOne('https://api.test/api/version').flush(release);
  });

  it('does not check again when the tab comes back sooner', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 29 * 60_000);
    showTab();

    ctrl.expectNone('https://api.test/api/version');
  });

  it('does not check while the tab is hidden', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 60 * 60_000);
    hideTab();

    ctrl.expectNone('https://api.test/api/version');
  });

  it('loads on a first freshness-gated call', () => {
    service.loadIfStale();

    ctrl.expectOne('https://api.test/api/version').flush(release);
  });

  it('skips a freshness-gated call within half an hour of the last load', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 29 * 60_000);
    service.loadIfStale();

    ctrl.expectNone('https://api.test/api/version');
  });

  it('loads on a freshness-gated call once half an hour has passed', () => {
    service.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    jest.setSystemTime(Date.now() + 30 * 60_000);
    service.loadIfStale();

    ctrl.expectOne('https://api.test/api/version').flush(release);
  });

  it('retries on a freshness-gated call after a failed load', () => {
    service.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush(null, { status: 502, statusText: 'Bad Gateway' });

    jest.setSystemTime(Date.now() + 60_000);
    service.loadIfStale();

    ctrl.expectOne('https://api.test/api/version').flush(release);
  });

  it('waits for the first load before checking on its own', () => {
    showTab();
    ctrl.expectNone('https://api.test/api/version');
  });
});
