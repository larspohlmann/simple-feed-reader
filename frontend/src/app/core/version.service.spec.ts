// src/app/core/version.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from './api';
import { VersionService } from './version.service';

describe('VersionService', () => {
  let svc: VersionService;
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
    svc = TestBed.inject(VersionService);
    ctrl = TestBed.inject(HttpTestingController);
  });
  afterEach(() => ctrl.verify());

  it('exposes the build the API reports', () => {
    svc.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    expect(svc.apiVersion()).toEqual(release);
    expect(svc.unavailable()).toBe(false);
  });

  it('marks the API version unavailable when the call fails', () => {
    svc.load();
    ctrl
      .expectOne('https://api.test/api/version')
      .flush(null, { status: 503, statusText: 'Service Unavailable' });

    expect(svc.apiVersion()).toBeNull();
    expect(svc.unavailable()).toBe(true);
  });

  it('re-checks on every load, because the server can be redeployed under a loaded bundle', () => {
    svc.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    const newer = { ...release, version: 'v0.5.0-dev.4' };
    svc.load();
    ctrl.expectOne('https://api.test/api/version').flush(newer);

    expect(svc.apiVersion()).toEqual(newer);
  });

  it('clears a previous failure once the endpoint answers again', () => {
    svc.load();
    ctrl.expectOne('https://api.test/api/version').flush(null, { status: 503, statusText: 'down' });
    expect(svc.unavailable()).toBe(true);

    svc.load();
    ctrl.expectOne('https://api.test/api/version').flush(release);

    expect(svc.unavailable()).toBe(false);
  });
});
