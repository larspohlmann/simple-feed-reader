// src/app/settings/admin/proxy/proxy-settings.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../../core/api';
import { ProxySettingsService, ProxySettingsState } from './proxy-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/proxy`;
const TEST_ENDPOINT = `${BASE}/api/admin/proxy/test`;

function state(over: Partial<ProxySettingsState> = {}): ProxySettingsState {
  return {
    enabled: false,
    directFallback: true,
    type: 'SOCKS5',
    host: 'proxy.example.com',
    port: 1080,
    username: null,
    remoteDns: false,
    hasPassword: false,
    passwordHint: '',
    ...over,
  };
}

describe('ProxySettingsService', () => {
  let service: ProxySettingsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        ProxySettingsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    service = TestBed.inject(ProxySettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function loadState(over: Partial<ProxySettingsState> = {}): void {
    service.load();
    http.expectOne(ENDPOINT).flush(state(over));
  }

  it('load() GETs the proxy endpoint and sets state', () => {
    service.load();

    const req = http.expectOne(ENDPOINT);
    expect(req.request.method).toBe('GET');
    req.flush(state());

    expect(service.state()).toEqual(state());
  });

  it('saveInstant composes the full body over last-saved state with password:null', () => {
    loadState();

    service.saveInstant({ enabled: true });

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({
      enabled: true,
      directFallback: true,
      type: 'SOCKS5',
      remoteDns: false,
      host: 'proxy.example.com',
      port: 1080,
      username: null,
      password: null,
    });

    put.flush(state({ enabled: true }));
    expect(service.saved()).toBe(true);
    expect(service.state()?.enabled).toBe(true);
  });

  it('setTypedField marks dirty without a request; save() PUTs base+draft', () => {
    loadState();

    service.setTypedField('host', 'x');
    expect(service.dirty()).toBe(true);
    http.expectNone(ENDPOINT);

    service.save();
    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.host).toBe('x');
    expect(put.request.body.port).toBe(1080);

    put.flush(state({ host: 'x' }));
    expect(service.dirty()).toBe(false);
  });

  it('testConnection() sets probe to ok on { ok: true, egressIp }', () => {
    service.testConnection();

    const req = http.expectOne(TEST_ENDPOINT);
    expect(req.request.method).toBe('POST');
    req.flush({ ok: true, egressIp: '1.2.3.4', reason: null });

    expect(service.probe()).toEqual({ status: 'ok', egressIp: '1.2.3.4' });
  });

  it('testConnection() sets probe to error on { ok: false, reason }', () => {
    service.testConnection();

    const req = http.expectOne(TEST_ENDPOINT);
    req.flush({ ok: false, egressIp: null, reason: 'connection refused' });

    expect(service.probe()).toEqual({ status: 'error', message: 'connection refused' });
  });

  it('testConnection() sets probe to error via parseProblem on an HTTP error', () => {
    service.testConnection();

    const req = http.expectOne(TEST_ENDPOINT);
    req.flush(
      { type: 'about:blank', title: 'Request failed', status: 500, detail: 'boom' },
      { status: 500, statusText: 'Server Error' },
    );

    expect(service.probe()).toEqual({ status: 'error', message: 'boom' });
  });

  it('a failed PUT leaves saved() false and sets failure()', () => {
    loadState();

    service.saveInstant({ enabled: true });

    const put = http.expectOne(ENDPOINT);
    put.flush(
      { type: 'about:blank', title: 'Request failed', status: 500, detail: 'nope' },
      { status: 500, statusText: 'Server Error' },
    );

    expect(service.saved()).toBe(false);
    expect(service.failure()).not.toBeNull();
  });
});
