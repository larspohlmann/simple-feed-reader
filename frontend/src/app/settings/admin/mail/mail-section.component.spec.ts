import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../../shared/toast/toast.service';
import { MailSectionComponent } from './mail-section.component';
import { MailSettingsState } from './mail-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/admin/mail`;
const TEST_ENDPOINT = `${BASE}/api/admin/mail/test`;
const RESET_ENDPOINT = `${BASE}/api/admin/mail/reset`;

function state(over: Partial<MailSettingsState> = {}): MailSettingsState {
  return {
    enabled: false,
    host: '',
    port: 587,
    username: null,
    encryption: 'starttls',
    fromAddress: '',
    fromName: '',
    hasPassword: false,
    passwordHint: '',
    hasSavedConfig: false,
    envFallbackConfigured: false,
    ...over,
  };
}

describe('MailSectionComponent', () => {
  let http: HttpTestingController;
  const toastStub = { show: jest.fn() };
  const dialogStub = { open: jest.fn() };

  function mount(initial: MailSettingsState = state()): ComponentFixture<MailSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [MailSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: ToastService, useValue: toastStub },
        { provide: Dialog, useValue: dialogStub },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(MailSectionComponent);
    fixture.detectChanges();
    http.expectOne(ENDPOINT).flush(initial);
    fixture.detectChanges();
    return fixture;
  }

  const enableToggleInput = (fixture: ComponentFixture<MailSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('#mail-enabled-toggle');

  const hostInput = (fixture: ComponentFixture<MailSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="mail-host"]');

  const passwordInput = (fixture: ComponentFixture<MailSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="mail-password"]');

  const portInput = (fixture: ComponentFixture<MailSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="mail-port"]');

  const fromAddressInput = (fixture: ComponentFixture<MailSectionComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('[data-testid="mail-from-address"]');

  const testButton = (fixture: ComponentFixture<MailSectionComponent>): HTMLButtonElement =>
    fixture.nativeElement.querySelector('[data-testid="mail-test-button"] button');

  const resetToEnvButton = (
    fixture: ComponentFixture<MailSectionComponent>,
  ): HTMLButtonElement | null =>
    fixture.nativeElement.querySelector('[data-testid="mail-reset-to-env"] button');

  beforeEach(() => {
    toastStub.show.mockReset();
    dialogStub.open.mockReset();
  });

  afterEach(() => http.verify());

  it('disables the enable toggle until a host is saved', () => {
    const fixture = mount(state({ host: '' }));

    expect(enableToggleInput(fixture).disabled).toBe(true);
  });

  it('enables the enable toggle once a host is saved', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    expect(enableToggleInput(fixture).disabled).toBe(false);
  });

  it('saves instantly when the enable toggle is flipped on a saved row', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', enabled: false, hasSavedConfig: true }),
    );

    enableToggleInput(fixture).click();
    fixture.detectChanges();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.enabled).toBe(true);
    put.flush(state({ host: 'smtp.example.com', enabled: true, hasSavedConfig: true }));
  });

  it('confirms a save with a toast that dismisses itself', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', enabled: false, hasSavedConfig: true }),
    );
    const i18n = TestBed.inject(TranslocoService);

    enableToggleInput(fixture).click();
    fixture.detectChanges();
    http
      .expectOne(ENDPOINT)
      .flush(state({ host: 'smtp.example.com', enabled: true, hasSavedConfig: true }));
    fixture.detectChanges();

    expect(toastStub.show).toHaveBeenCalledWith({
      message: i18n.translate('settings.mail.saved'),
      durationMs: CONFIRMATION_DURATION_MS,
    });
  });

  it('does not instant-save the enable toggle or encryption before a row exists', () => {
    const fixture = mount(
      state({
        host: 'smtp.example.com',
        username: 'sam',
        enabled: true,
        hasSavedConfig: false,
      }),
    );

    enableToggleInput(fixture).click();
    fixture.detectChanges();
    http.expectNone(ENDPOINT);

    const encryptionSelect: HTMLSelectElement = fixture.nativeElement.querySelector(
      '[data-testid="mail-encryption"]',
    );
    encryptionSelect.value = 'tls';
    encryptionSelect.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    http.expectNone(ENDPOINT);
  });

  it('an explicit Save on a no-row install carries the staged toggle/encryption and password', () => {
    const fixture = mount(
      state({
        host: 'smtp.example.com',
        username: 'sam',
        enabled: true,
        encryption: 'starttls',
        hasSavedConfig: false,
      }),
    );

    const encryptionSelect: HTMLSelectElement = fixture.nativeElement.querySelector(
      '[data-testid="mail-encryption"]',
    );
    encryptionSelect.value = 'tls';
    encryptionSelect.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    passwordInput(fixture).value = 'secret';
    passwordInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    fixture.componentInstance.onSave();
    fixture.detectChanges();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.enabled).toBe(true);
    expect(put.request.body.encryption).toBe('tls');
    expect(put.request.body.password).toBe('secret');
    put.flush(
      state({ host: 'smtp.example.com', username: 'sam', enabled: true, hasSavedConfig: true }),
    );
  });

  it('blocks a save with an empty host while enabled, guiding the user to fix it or turn mail off', () => {
    const fixture = mount(state({ host: 'smtp.example.com', enabled: true, hasSavedConfig: true }));

    hostInput(fixture).value = '';
    hostInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    fixture.componentInstance.onSave();
    fixture.detectChanges();

    expect(fixture.componentInstance.fieldError('host')).not.toBeNull();
    expect(hostInput(fixture).getAttribute('aria-invalid')).toBe('true');
    http.expectNone(ENDPOINT);
  });

  it('still turns mail off from the form once the toggle itself is switched off, so the row cannot get stuck on', () => {
    const fixture = mount(state({ host: 'smtp.example.com', enabled: true, hasSavedConfig: true }));

    hostInput(fixture).value = '';
    hostInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    fixture.componentInstance.enabled.set(false);
    fixture.componentInstance.onSave();
    fixture.detectChanges();

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.host).toBe('');
    expect(put.request.body.enabled).toBe(false);
    put.flush(state({ host: '', enabled: false, hasSavedConfig: true }));
  });

  it('marks the save bar dirty when the host is edited', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    hostInput(fixture).value = 'other.example.com';
    hostInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.dirty()).toBe(true);
  });

  it('disables the Test button while dirty and calls testConnection() when clicked', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

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
    req.flush({ ok: true, reason: null });
  });

  it('marks a successful probe with a tick and the testOk message', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    testButton(fixture).click();
    fixture.detectChanges();
    http.expectOne(TEST_ENDPOINT).flush({ ok: true, reason: null });
    fixture.detectChanges();

    const glyph: HTMLElement | null = fixture.nativeElement.querySelector('.probe-status app-icon');
    expect(glyph?.textContent?.trim()).toBe('check');
    expect(glyph?.className).toContain('ok');
  });

  it('renders an error banner with the reason on a failed probe', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));
    const i18n = TestBed.inject(TranslocoService);

    testButton(fixture).click();
    fixture.detectChanges();
    http.expectOne(TEST_ENDPOINT).flush({ ok: false, reason: 'connection refused' });
    fixture.detectChanges();

    const banner: HTMLElement | null = fixture.nativeElement.querySelector('app-error-banner');
    const expectedMessage = i18n.translate('settings.mail.testFailed', {
      reason: 'connection refused',
    });
    expect(banner).not.toBeNull();
    expect(banner?.textContent).toContain(expectedMessage);
  });

  const gmailHint = (fixture: ComponentFixture<MailSectionComponent>): HTMLElement | null =>
    fixture.nativeElement.querySelector('.gmail-hint');

  function failProbe(fixture: ComponentFixture<MailSectionComponent>, reason: string): void {
    testButton(fixture).click();
    fixture.detectChanges();
    http.expectOne(TEST_ENDPOINT).flush({ ok: false, reason });
    fixture.detectChanges();
  }

  it('shows the Gmail App Password hint when the failure carries the app-password signature', () => {
    const fixture = mount(state({ host: 'smtp.gmail.com' }));

    failProbe(
      fixture,
      'Expected response code 235 but got code 534, with message "534-5.7.9 ' +
        'Application-specific password required. …InvalidSecondFactor"',
    );

    const hint = gmailHint(fixture);
    expect(hint).not.toBeNull();
    const link = hint?.querySelector('a');
    expect(link?.getAttribute('href')).toBe('https://myaccount.google.com/apppasswords');
    expect(hint?.textContent).toContain('Send mail as');
  });

  it('hides the Gmail hint for a failure that lacks the app-password signature', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    failProbe(fixture, 'connection refused');

    expect(gmailHint(fixture)).toBeNull();
  });

  it('renders the stored password hint as a placeholder, never the secret itself', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', hasPassword: true, passwordHint: '••••ab12' }),
    );

    expect(passwordInput(fixture).placeholder).toBe('••••ab12');
    expect(passwordInput(fixture).value).toBe('');
    expect(passwordInput(fixture).type).toBe('password');
  });

  it('maps a blank password to null on the service', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    passwordInput(fixture).value = '';
    passwordInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.draft()).toEqual({ password: null });
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

  it('hides the reset-to-environment control when there is no saved config', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', hasSavedConfig: false, envFallbackConfigured: true }),
    );

    expect(resetToEnvButton(fixture)).toBeNull();
  });

  it('hides the reset-to-environment control when no env fallback is configured', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', hasSavedConfig: true, envFallbackConfigured: false }),
    );

    expect(resetToEnvButton(fixture)).toBeNull();
  });

  it('shows the reset-to-environment control once both conditions hold', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', hasSavedConfig: true, envFallbackConfigured: true }),
    );

    expect(resetToEnvButton(fixture)).not.toBeNull();
  });

  it('calls svc.reset() once the confirm dialog is accepted', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', hasSavedConfig: true, envFallbackConfigured: true }),
    );
    dialogStub.open.mockReturnValue({ closed: of(true) });

    resetToEnvButton(fixture)?.click();
    fixture.detectChanges();

    http.expectOne(RESET_ENDPOINT).flush(state({ host: '', hasSavedConfig: false }));
  });

  it('does not call svc.reset() when the confirm dialog is dismissed', () => {
    const fixture = mount(
      state({ host: 'smtp.example.com', hasSavedConfig: true, envFallbackConfigured: true }),
    );
    dialogStub.open.mockReturnValue({ closed: of(false) });
    const resetSpy = jest.spyOn(fixture.componentInstance.svc, 'reset');

    resetToEnvButton(fixture)?.click();
    fixture.detectChanges();

    expect(resetSpy).not.toHaveBeenCalled();
    http.expectNone(RESET_ENDPOINT);
  });

  it('marks fromAddress invalid and shows a message for a bad email on save', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    fromAddressInput(fixture).value = 'not-an-email';
    fromAddressInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    fixture.componentInstance.onSave();
    fixture.detectChanges();

    expect(fixture.componentInstance.fieldError('fromAddress')).not.toBeNull();
    expect(fromAddressInput(fixture).getAttribute('aria-invalid')).toBe('true');
    http.expectNone(ENDPOINT);
  });

  it('maps a 422 errors map onto the offending field', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    fixture.componentInstance.onSave();
    fixture.detectChanges();

    http.expectOne(ENDPOINT).flush(
      {
        type: 'validation_error',
        title: 'Validation failed',
        status: 422,
        errors: { port: ['This value should be between 1 and 65535.'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();

    expect(fixture.componentInstance.fieldError('port')).toContain(
      'This value should be between 1 and 65535.',
    );
    expect(portInput(fixture).getAttribute('aria-invalid')).toBe('true');
  });

  it('clears a field error when the user edits that field', () => {
    const fixture = mount(state({ host: 'smtp.example.com' }));

    fixture.componentInstance.onSave();
    fixture.detectChanges();

    http.expectOne(ENDPOINT).flush(
      {
        type: 'validation_error',
        title: 'Validation failed',
        status: 422,
        errors: { host: ['Host looks wrong.'] },
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();
    expect(fixture.componentInstance.fieldError('host')).not.toBeNull();

    hostInput(fixture).value = 'other.example.com';
    hostInput(fixture).dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(fixture.componentInstance.fieldError('host')).toBeNull();
    expect(hostInput(fixture).getAttribute('aria-invalid')).toBe('false');
  });
});
