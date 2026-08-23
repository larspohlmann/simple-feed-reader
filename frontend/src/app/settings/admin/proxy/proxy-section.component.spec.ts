// src/app/settings/admin/proxy/proxy-section.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
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
    remoteDns: false,
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

  it('confirms a save with a toast that dismisses itself', () => {
    const fixture = mount(state({ host: 'proxy.example.com', enabled: false }));

    enableToggleInput(fixture).click();
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(state({ host: 'proxy.example.com', enabled: true }));
    fixture.detectChanges();

    expect(toastStub.show).toHaveBeenCalledWith({
      message: 'Proxy settings saved',
      durationMs: CONFIRMATION_DURATION_MS,
    });
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

  // The outcome used to sit beside the button inside the control slot, so the
  // button jumped left the moment a result arrived.
  it('reports the outcome in the row description, leaving the button in place', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    testButton(fixture).click();
    fixture.detectChanges();
    http.expectOne(TEST_ENDPOINT).flush({ ok: true, egressIp: '203.0.113.9', reason: null });
    fixture.detectChanges();

    const row = testButton(fixture).closest('app-settings-row');
    expect(row?.querySelector('.row-desc')?.textContent).toContain('203.0.113.9');
    expect(row?.querySelector('.row-control')?.textContent).not.toContain('203.0.113.9');
  });

  const probeGlyph = (fixture: ComponentFixture<ProxySectionComponent>): HTMLElement | null =>
    fixture.nativeElement.querySelector('.probe-status app-icon');

  it('marks a successful probe with a tick', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    testButton(fixture).click();
    fixture.detectChanges();
    http.expectOne(TEST_ENDPOINT).flush({ ok: true, egressIp: '203.0.113.9', reason: null });
    fixture.detectChanges();

    expect(probeGlyph(fixture)?.textContent?.trim()).toBe('check');
    expect(probeGlyph(fixture)?.className).toContain('ok');
  });

  it('marks a failed probe with a cross', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    testButton(fixture).click();
    fixture.detectChanges();
    http.expectOne(TEST_ENDPOINT).flush({ ok: false, egressIp: null, reason: 'refused' });
    fixture.detectChanges();

    expect(probeGlyph(fixture)?.textContent?.trim()).toBe('close');
    expect(probeGlyph(fixture)?.className).toContain('failed');
  });

  // The slot is there before any probe runs, so the glyph cannot widen the
  // control and shove the button sideways when it appears.
  it('keeps the glyph slot in place before any probe has run', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    expect(fixture.nativeElement.querySelector('.probe-status')).not.toBeNull();
    expect(probeGlyph(fixture)).toBeNull();
  });

  it('weights the test button once it can actually run', () => {
    const fixture = mount(state({ host: 'proxy.example.com' }));

    expect(testButton(fixture).disabled).toBe(false);
    expect(testButton(fixture).className).toContain('accent-outline');
  });

  it('leaves the test button unweighted while it cannot run', () => {
    const fixture = mount(state());

    expect(testButton(fixture).disabled).toBe(true);
    expect(testButton(fixture).className).toContain('default');
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

  const remoteDnsToggle = (fixture: ComponentFixture<ProxySectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('#proxy-remote-dns-toggle');

  it('saves the remote-DNS switch instantly, like the other toggles', () => {
    const fixture = mount(state({ host: 'proxy.example' }));

    remoteDnsToggle(fixture).click();
    fixture.detectChanges();

    const request = http.expectOne((r) => r.url === ENDPOINT && r.method === 'PUT');
    expect(request.request.body.remoteDns).toBe(true);
    request.flush(state({ host: 'proxy.example', remoteDns: true }));
  });

  // Only SOCKS5 gives the client a choice: an HTTP proxy always resolves the
  // name itself, so the switch would be a lie on that type.
  it('disables the remote-DNS switch for an HTTP proxy', () => {
    const fixture = mount(state({ host: 'proxy.example', type: 'HTTP' }));

    expect(remoteDnsToggle(fixture).disabled).toBe(true);
  });

  it('enables the remote-DNS switch for a SOCKS5 proxy', () => {
    const fixture = mount(state({ host: 'proxy.example', type: 'SOCKS5' }));

    expect(remoteDnsToggle(fixture).disabled).toBe(false);
  });

  it('keeps a pending typed edit visible when an instant toggle saves', () => {
    const fixture = mount(state({ host: 'old.example.com' }));

    const host = hostInput(fixture);
    host.value = 'new.example.com';
    host.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    // Flip the instant "direct fallback" toggle: it persists from the SAVED
    // state, so the server echoes back the host the admin has not saved yet.
    const toggle: HTMLInputElement = fixture.nativeElement.querySelector(
      '#proxy-direct-fallback-toggle',
    );
    toggle.click();
    fixture.detectChanges();
    http
      .expectOne((r) => r.url === ENDPOINT && r.method === 'PUT')
      .flush(state({ host: 'old.example.com', directFallback: false }));
    fixture.detectChanges();

    // The typed edit must still be on screen, not silently held in the draft.
    expect(hostInput(fixture).value).toBe('new.example.com');
    expect(fixture.componentInstance.svc.dirty()).toBe(true);
  });

  it('restores the last-saved values on Reset', () => {
    const fixture = mount(state({ host: 'old.example.com', username: 'sam' }));

    const host = hostInput(fixture);
    host.value = 'new.example.com';
    host.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(hostInput(fixture).value).toBe('new.example.com');

    fixture.componentInstance.onReset();
    fixture.detectChanges();

    expect(hostInput(fixture).value).toBe('old.example.com');
    expect(fixture.componentInstance.svc.dirty()).toBe(false);
  });

  it('keeps a cleared username cleared rather than reverting to server truth', () => {
    const fixture = mount(state({ host: 'old.example.com', username: 'sam' }));

    const username: HTMLInputElement = fixture.nativeElement.querySelector(
      '[data-testid="proxy-username"]',
    );
    username.value = '';
    username.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    const shown: HTMLInputElement = fixture.nativeElement.querySelector(
      '[data-testid="proxy-username"]',
    );
    expect(shown.value).toBe('');
    expect(fixture.componentInstance.svc.draft()).toEqual({ username: '' });
  });
});
