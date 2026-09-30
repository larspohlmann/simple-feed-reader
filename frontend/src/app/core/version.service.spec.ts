import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from './api';
import { VersionService } from './version.service';

describe('VersionService', () => {
  let service: VersionService;
  let ctrl: HttpTestingController;

  const release = { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '2026-07-27T10:04:11Z' };

  beforeEach(() => {
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
  afterEach(() => ctrl.verify());

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
});
