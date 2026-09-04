import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../../core/api';
import { MailSettingsService, MailSettingsState } from './mail-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/mail`;
const TEST_ENDPOINT = `${BASE}/api/admin/mail/test`;
const RESET_ENDPOINT = `${BASE}/api/admin/mail/reset`;

function state(over: Partial<MailSettingsState> = {}): MailSettingsState {
  return {
    enabled: false,
    host: 'mail.example.com',
    port: 587,
    username: null,
    encryption: 'starttls',
    fromAddress: 'reader@example.com',
    fromName: 'Reader',
    hasPassword: false,
    passwordHint: '',
    hasSavedConfig: false,
    envFallbackConfigured: false,
    ...over,
  };
}

describe('MailSettingsService', () => {
  let service: MailSettingsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        MailSettingsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    service = TestBed.inject(MailSettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function loadState(over: Partial<MailSettingsState> = {}): void {
    service.load();
    http.expectOne(ENDPOINT).flush(state(over));
  }

  it('load() GETs the mail endpoint and sets state', () => {
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
      host: 'mail.example.com',
      port: 587,
      username: null,
      encryption: 'starttls',
      fromAddress: 'reader@example.com',
      fromName: 'Reader',
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
    expect(put.request.body.port).toBe(587);

    put.flush(state({ host: 'x' }));
    expect(service.dirty()).toBe(false);
  });

  it('bodyFromState sends password:null unless a typed edit sets it', () => {
    loadState();

    service.setTypedField('password', 'secret');
    service.save();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.password).toBe('secret');

    put.flush(state());
  });

  it('testConnection() sets probe to ok on { ok: true }', () => {
    service.testConnection();

    const req = http.expectOne(TEST_ENDPOINT);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({});
    req.flush({ ok: true, reason: null });

    expect(service.probe()).toEqual({ status: 'ok' });
  });

  it('testConnection() sets probe to error on { ok: false, reason }', () => {
    service.testConnection();

    const req = http.expectOne(TEST_ENDPOINT);
    req.flush({ ok: false, reason: 'connection refused' });

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

  it('reset() POSTs to the reset endpoint and commits the returned state', () => {
    loadState();

    service.setTypedField('host', 'x');
    expect(service.dirty()).toBe(true);

    service.reset();

    const req = http.expectOne(RESET_ENDPOINT);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({});

    req.flush(state({ envFallbackConfigured: true }));

    expect(service.saved()).toBe(true);
    expect(service.dirty()).toBe(false);
    expect(service.state()).toEqual(state({ envFallbackConfigured: true }));
  });
});
