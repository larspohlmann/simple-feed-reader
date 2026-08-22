// src/app/settings/admin/proxy/proxy-section.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ToastService } from '../../../shared/toast/toast.service';
import { ProxySectionComponent } from './proxy-section.component';
import { ProxySettingsState } from './proxy-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/proxy`;
const TEST_ENDPOINT = `${BASE}/api/admin/proxy/test`;

function state(over: Partial<ProxySettingsState> = {}): ProxySettingsState {
  return {
    enabled: false,
    directFallback: true,
    type: 'SOCKS5',
    host: '',
    port: 1080,
    username: null,
    hasPassword: false,
    passwordHint: '',
    ...over,
  };
}

describe('ProxySectionComponent', () => {
  let http: HttpTestingController;
  const toastStub = { show: jest.fn() };

  function mount(initial: ProxySettingsState = state()): ComponentFixture<ProxySectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [ProxySectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: ToastService, useValue: toastStub },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(ProxySectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(initial);
    fixture.detectChanges();
    return fixture;
  }

  const enableToggleInput = (fixture: ComponentFixture<ProxySectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('#proxy-enabled-toggle');

  const hostInput = (fixture: ComponentFixture<ProxySectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="proxy-host"]');

  const passwordInput = (fixture: ComponentFixture<ProxySectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="proxy-password"]');

  const testButton = (fixture: ComponentFixture<ProxySectionComponent>): HTMLButtonElement =>
    fixture.nativeElement.querySelector('[data-testid="proxy-test-button"] button');

  const errorBanner = (fixture: ComponentFixture<ProxySectionComponent>): HTMLElement | null =>
    fixture.nativeElement.querySelector('app-error-banner');

  beforeEach(() => {
    toastStub.show.mockReset();
  });

  afterEach(() => http.verify());

  it('disables the enable toggle until a host is saved', () => {
    const fixture = mount(state({ host: '' }));

    expect(enableToggleInput(fixture).disabled).toBe(true);
  });

  it('enables the enable toggle once a host is saved', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    expect(enableToggleInput(fixture).disabled).toBe(false);
  });

  it('saves instantly when the enable toggle is flipped', () => {
    const fixture = mount(state({ host: 'proxy.example.com', enabled: false }));

    enableToggleInput(fixture).click();
    fixture.detectChanges();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.enabled).toBe(true);
    put.flush(state({ host: 'proxy.example.com', enabled: true }));
  });

  it('marks the save bar dirty when the host is edited', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    hostInput(fixture).value = 'other.example.com';
    hostInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.dirty()).toBe(true);
  });

  it('disables the Test button while dirty and calls testConnection() when clicked', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    hostInput(fixture).value = 'other.example.com';
    hostInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(testButton(fixture).disabled).toBe(true);

    fixture.componentInstance.svc.discardDraft();
    fixture.detectChanges();
    expect(testButton(fixture).disabled).toBe(false);

    testButton(fixture).click();
    fixture.detectChanges();

    const req = http.expectOne(TEST_ENDPOINT);
    expect(req.request.method).toBe('POST');
    req.flush({ ok: true, egressIp: '203.0.113.9', reason: null });
  });

  it('renders the egress IP on a successful probe', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    testButton(fixture).click();
    fixture.detectChanges();
    http.expectOne(TEST_ENDPOINT).flush({ ok: true, egressIp: '203.0.113.9', reason: null });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('203.0.113.9');
  });

  it('renders an error banner on a failed probe', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    testButton(fixture).click();
    fixture.detectChanges();
    http
      .expectOne(TEST_ENDPOINT)
      .flush({ ok: false, egressIp: null, reason: 'connection refused' });
    fixture.detectChanges();

    expect(errorBanner(fixture)).not.toBeNull();
    expect(errorBanner(fixture)?.textContent).toContain('connection refused');
  });

  it('renders the stored password hint as a placeholder, never the secret itself', () => {
    const fixture = mount(
      state({ host: 'proxy.example.com', hasPassword: true, passwordHint: '••••ab12' }),
    );

    expect(passwordInput(fixture).placeholder).toBe('••••ab12');
    expect(passwordInput(fixture).value).toBe('');
    expect(passwordInput(fixture).type).toBe('password');
  });
});
