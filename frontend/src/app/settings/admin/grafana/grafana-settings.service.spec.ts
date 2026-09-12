import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { GrafanaSettingsService, GrafanaSettingsState } from './grafana-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/grafana`;

function state(over: Partial<GrafanaSettingsState> = {}): GrafanaSettingsState {
  return {
    lokiPushUrl: null,
    lokiPushUrlDefault: 'http://loki:3100',
    lokiPushUrlEffective: 'http://loki:3100',
    lokiUsername: null,
    grafanaUrl: null,
    grafanaUrlDefault: '',
    grafanaUrlEffective: null,
    hasToken: false,
    tokenHint: '',
    containerPresent: true,
    pyroscopePushUrl: null,
    pyroscopePushUrlDefault: 'http://pyroscope:4040',
    pyroscopePushUrlEffective: 'http://pyroscope:4040',
    profilingEnabled: false,
    profilingContainerPresent: true,
    profilerAvailable: true,
    ...over,
  };
}

describe('GrafanaSettingsService', () => {
  let service: GrafanaSettingsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        GrafanaSettingsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    service = TestBed.inject(GrafanaSettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function loadState(over: Partial<GrafanaSettingsState> = {}): void {
    service.load();
    http.expectOne(ENDPOINT).flush(state(over));
  }

  it('load() GETs the grafana endpoint and sets state', () => {
    service.load();

    const req = http.expectOne(ENDPOINT);
    expect(req.request.method).toBe('GET');
    req.flush(state());

    expect(service.state()).toEqual(state());
  });

  it('save() PUTs the body with token:null when untouched', () => {
    loadState({ lokiPushUrl: 'https://loki.example.com', lokiUsername: 'sam' });

    service.setTypedField('grafanaUrl', 'https://grafana.example.com');
    service.save();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({
      lokiPushUrl: 'https://loki.example.com',
      lokiUsername: 'sam',
      grafanaUrl: 'https://grafana.example.com',
      token: null,
      removeToken: false,
      pyroscopePushUrl: null,
      profilingEnabled: false,
    });

    put.flush(state({ lokiPushUrl: 'https://loki.example.com', lokiUsername: 'sam' }));
    expect(service.saved()).toBe(true);
  });

  it("setTypedField('token', 'x') then save() sends token: 'x'", () => {
    loadState();

    service.setTypedField('token', 'x');
    service.save();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.token).toBe('x');

    put.flush(state({ hasToken: true }));
    expect(service.state()?.hasToken).toBe(true);
  });

  it('removeToken() PUTs removeToken:true and commits the returned state', () => {
    loadState({ hasToken: true });

    service.removeToken();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.removeToken).toBe(true);

    put.flush(state({ hasToken: false }));

    expect(service.saved()).toBe(true);
    expect(service.state()).toEqual(state({ hasToken: false }));
  });

  it('a failed PUT leaves saved() false and sets failure()', () => {
    loadState();

    service.setTypedField('grafanaUrl', 'https://grafana.example.com');
    service.save();

    const put = http.expectOne(ENDPOINT);
    put.flush(
      { type: 'about:blank', title: 'Request failed', status: 500, detail: 'nope' },
      { status: 500, statusText: 'Server Error' },
    );

    expect(service.saved()).toBe(false);
    expect(service.failure()).not.toBeNull();
  });
});
