import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { API_BASE_URL } from '../../../core/api';
import { ConfirmDialogComponent } from '../../../shared/confirm-dialog/confirm-dialog.component';
import { AdminSettingsComponent } from './admin-settings.component';
import { InstanceSettings, InstanceSettingsUpdate } from './admin-settings-api';

const BASE_SETTINGS: InstanceSettings = {
  requireEmailConfirmation: false,
  requireApproval: false,
  mailEnabled: true,
  publicBaseUrl: null,
  publicBaseUrlDefault: 'http://localhost:4200',
  passkeyRpId: null,
  passkeyRpName: null,
  passkeyRpIdEffective: 'example.com',
  passkeySignInEnabled: true,
};

const BASE_UPDATE: InstanceSettingsUpdate = {
  requireEmailConfirmation: false,
  requireApproval: false,
  publicBaseUrl: null,
  passkeyRpId: null,
  passkeyRpName: null,
  invalidateExistingPasskeys: false,
  passkeySignInEnabled: true,
};

describe('AdminSettingsComponent', () => {
  let ctrl: HttpTestingController;
  // Only the 409 confirmation opens a dialog here -- stubbed the same way
  // `PasskeysGroupComponent`'s own spec stubs `Dialog`, rather than rendering
  // the real CDK overlay.
  const dialogStub = { open: jest.fn() };

  beforeEach(() => dialogStub.open.mockReset());

  function mount() {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: dialogStub },
      ],
    });
    const fixture = TestBed.createComponent(AdminSettingsComponent);
    fixture.detectChanges(); // ngOnInit → initial load
    ctrl = TestBed.inject(HttpTestingController);
    return fixture;
  }

  afterEach(() => ctrl.verify());

  function flushInitial(
    fixture: ReturnType<typeof mount>,
    settings: InstanceSettings = BASE_SETTINGS,
  ) {
    ctrl.expectOne('https://api.test/api/admin/settings').flush(settings);
    fixture.detectChanges();
  }

  const savebar = (fixture: ReturnType<typeof mount>) =>
    (fixture.nativeElement as HTMLElement).querySelector('app-settings-save-bar')!;
  const saveButton = (fixture: ReturnType<typeof mount>) =>
    savebar(fixture).querySelector<HTMLButtonElement>('app-button[variant="primary"] button')!;
  const resetButton = (fixture: ReturnType<typeof mount>) =>
    savebar(fixture).querySelector<HTMLButtonElement>('app-button[variant="ghost"] button')!;

  /** Text fields are dirty-tracked behind the save bar, so an edit is two
   *  steps: type, then Save. */
  function type(fixture: ReturnType<typeof mount>, selector: string, value: string) {
    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(selector)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    return input;
  }

  it('loads the settings on init and renders both toggles', () => {
    const fixture = mount();
    flushInitial(fixture);

    const element = fixture.nativeElement as HTMLElement;
    const checkboxes = element.querySelectorAll('input[type="checkbox"]');
    expect(checkboxes.length).toBe(3);
  });

  it('disables the email-confirmation control and shows an explanation when mail is off', () => {
    const fixture = mount();
    flushInitial(fixture, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      mailEnabled: false,
    });

    const element = fixture.nativeElement as HTMLElement;
    const checkboxes = element.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
    const emailConfirmation = checkboxes[0];
    const approval = checkboxes[1];

    expect(emailConfirmation.checked).toBe(true);
    expect(emailConfirmation.disabled).toBe(true);
    expect(approval.disabled).toBe(false);
    expect(element.textContent).toContain('This instance sends no mail');
  });

  it('leaves the email-confirmation control enabled and shows no mailless explanation when mail is on', () => {
    const fixture = mount();
    flushInitial(fixture, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
    });

    const element = fixture.nativeElement as HTMLElement;
    const emailConfirmation = element.querySelector<HTMLInputElement>('input[type="checkbox"]')!;
    expect(emailConfirmation.disabled).toBe(false);
    expect(element.textContent).not.toContain('This instance sends no mail');
  });

  it('toggling approval calls update and applies the response', () => {
    const fixture = mount();
    flushInitial(fixture, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      mailEnabled: false,
    });

    const element = fixture.nativeElement as HTMLElement;
    const approval = element.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')[1];
    approval.checked = false;
    approval.dispatchEvent(new Event('change'));

    const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(testRequest.request.method).toBe('PUT');
    expect(testRequest.request.body).toEqual({
      ...BASE_UPDATE,
      requireEmailConfirmation: true,
      requireApproval: false,
    });
    testRequest.flush({
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: false,
      mailEnabled: false,
    });

    fixture.detectChanges();
    expect(fixture.componentInstance.requireApproval()).toBe(false);
  });

  /** #624 follow-up: the third toggle, round-tripped the same way `toggling
   *  approval calls update and applies the response` proves the second one. */
  it('toggling passkey sign-in calls update and applies the response', () => {
    const fixture = mount();
    flushInitial(fixture);

    const element = fixture.nativeElement as HTMLElement;
    const passkeyToggle = element.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')[2];
    expect(passkeyToggle.checked).toBe(true);
    passkeyToggle.checked = false;
    passkeyToggle.dispatchEvent(new Event('change'));

    const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(testRequest.request.method).toBe('PUT');
    expect(testRequest.request.body).toEqual({ ...BASE_UPDATE, passkeySignInEnabled: false });
    testRequest.flush({ ...BASE_SETTINGS, passkeySignInEnabled: false });

    fixture.detectChanges();
    expect(fixture.componentInstance.passkeySignInEnabled()).toBe(false);
  });

  it('surfaces an error banner with a retry when the load fails', () => {
    const fixture = mount();
    ctrl
      .expectOne('https://api.test/api/admin/settings')
      .flush(
        { type: 'about:blank', title: 'Down', status: 500 },
        { status: 500, statusText: 'Server Error' },
      );
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('app-error-banner')).not.toBeNull();

    const retry = element.querySelector('[role="alert"] button') as HTMLButtonElement;
    retry.click();
    ctrl.expectOne('https://api.test/api/admin/settings').flush(BASE_SETTINGS);
  });

  it('renders both switches as settings rows in one group', () => {
    const fixture = mount();
    flushInitial(fixture);
    const element = fixture.nativeElement as HTMLElement;

    expect(element.querySelectorAll('app-settings-group').length).toBe(1);
    expect(element.querySelectorAll('app-settings-row app-toggle').length).toBe(3);
  });

  it('toggles the control when the visible label text is clicked, not only the switch', () => {
    const fixture = mount();
    flushInitial(fixture);
    const element = fixture.nativeElement as HTMLElement;

    const labels = element.querySelectorAll<HTMLLabelElement>('.row-title label');
    const checkboxes = element.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
    // Three toggle rows plus the text rows (publicBaseUrl, passkeyRpId, passkeyRpName).
    expect(labels.length).toBe(6);
    expect(labels[0].htmlFor).toBe(checkboxes[0].id);
    expect(labels[1].htmlFor).toBe(checkboxes[1].id);

    labels[1].click();
    fixture.detectChanges();

    const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(testRequest.request.body).toEqual({ ...BASE_UPDATE, requireApproval: true });
    testRequest.flush({ ...BASE_SETTINGS, requireApproval: true });
  });

  it('saving the public base URL sends it in the update and applies the response', () => {
    const fixture = mount();
    flushInitial(fixture, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
    });

    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
      '#public-base-url-input',
    )!;
    input.value = 'https://reader.example.ts.net/reader';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    saveButton(fixture).click();

    const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(testRequest.request.method).toBe('PUT');
    expect(testRequest.request.body).toEqual({
      ...BASE_UPDATE,
      requireEmailConfirmation: true,
      requireApproval: true,
      publicBaseUrl: 'https://reader.example.ts.net/reader',
    });
    testRequest.flush({
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      publicBaseUrl: 'https://reader.example.ts.net/reader',
    });
    fixture.detectChanges();
    expect(fixture.componentInstance.publicBaseUrl()).toBe('https://reader.example.ts.net/reader');
  });

  it('uses the deployment default as the public base URL placeholder', () => {
    const fixture = mount();
    flushInitial(fixture, {
      ...BASE_SETTINGS,
      publicBaseUrlDefault: 'http://localhost:4200',
    });

    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
      '#public-base-url-input',
    )!;
    expect(input.placeholder).toBe('http://localhost:4200');
  });

  it('clearing the public base URL sends null', () => {
    const fixture = mount();
    flushInitial(fixture, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      publicBaseUrl: 'https://old.example/',
    });

    const input = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
      '#public-base-url-input',
    )!;
    input.value = '   ';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    saveButton(fixture).click();

    const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(testRequest.request.body).toEqual({
      ...BASE_UPDATE,
      requireEmailConfirmation: true,
      requireApproval: true,
    });
    testRequest.flush({
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
    });
  });

  describe('passkey relying-party fields', () => {
    it('renders both fields and round-trips a saved value', () => {
      const fixture = mount();
      flushInitial(fixture, {
        ...BASE_SETTINGS,
        passkeyRpId: 'reader.example.com',
        passkeyRpName: 'My Reader',
      });

      const element = fixture.nativeElement as HTMLElement;
      const idInput = element.querySelector<HTMLInputElement>('#passkey-rp-id-input')!;
      const nameInput = element.querySelector<HTMLInputElement>('#passkey-rp-name-input')!;

      expect(idInput.value).toBe('reader.example.com');
      expect(nameInput.value).toBe('My Reader');

      idInput.value = 'other.example.com';
      idInput.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(testRequest.request.body).toEqual({
        ...BASE_UPDATE,
        passkeyRpId: 'other.example.com',
        passkeyRpName: 'My Reader',
      });
      testRequest.flush({
        ...BASE_SETTINGS,
        passkeyRpId: 'other.example.com',
        passkeyRpName: 'My Reader',
        passkeyRpIdEffective: 'other.example.com',
      });
      fixture.detectChanges();
      expect(fixture.componentInstance.passkeyRpId()).toBe('other.example.com');
    });

    it('sends an empty relying-party id as null, restoring the fallback', () => {
      const fixture = mount();
      flushInitial(fixture, { ...BASE_SETTINGS, passkeyRpId: 'reader.example.com' });

      const idInput = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = '   ';
      idInput.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(testRequest.request.body).toEqual({ ...BASE_UPDATE, passkeyRpId: null });
      testRequest.flush(BASE_SETTINGS);
    });

    it('sends an empty relying-party name as null', () => {
      const fixture = mount();
      flushInitial(fixture, { ...BASE_SETTINGS, passkeyRpName: 'My Reader' });

      const nameInput = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-name-input',
      )!;
      nameInput.value = '';
      nameInput.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(testRequest.request.body).toEqual({ ...BASE_UPDATE, passkeyRpName: null });
      testRequest.flush(BASE_SETTINGS);
    });

    it('uses passkeyRpIdEffective as the placeholder, not a hard-coded host', () => {
      const fixture = mount();
      flushInitial(fixture, { ...BASE_SETTINGS, passkeyRpIdEffective: 'reader.example.org' });

      const idInput = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      expect(idInput.placeholder).toBe('reader.example.org');
    });

    it("interpolates passkeyRpIdEffective into the field's description", () => {
      const fixture = mount();
      flushInitial(fixture, { ...BASE_SETTINGS, passkeyRpIdEffective: 'reader.example.org' });

      const element = fixture.nativeElement as HTMLElement;
      expect(element.textContent).toContain('reader.example.org');
    });

    it('renders a 422 validation message from the server', () => {
      const fixture = mount();
      flushInitial(fixture);

      const idInput = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = 'not-this-host.example';
      idInput.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      ctrl.expectOne('https://api.test/api/admin/settings').flush(
        {
          type: 'validation_error',
          title: 'Validation failed',
          status: 422,
          detail: 'One or more fields are invalid.',
          errors: {
            passkeyRpId: [
              'Must be the host, or a registrable parent domain of the host, that the reader is served from.',
            ],
          },
        },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
      fixture.detectChanges();

      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector('app-error-banner')).not.toBeNull();
      // The server's per-field reason, not the shared 422 detail, which names
      // neither the field nor what is wrong with it.
      expect(element.textContent).toContain(
        'Must be the host, or a registrable parent domain of the host',
      );
      expect(element.textContent).not.toContain('One or more fields are invalid.');
    });

    it('keeps the form and the rejected edit on screen when a save fails', () => {
      const fixture = mount();
      flushInitial(fixture);

      type(fixture, '#passkey-rp-id-input', 'not-this-host.example');
      saveButton(fixture).click();
      ctrl
        .expectOne('https://api.test/api/admin/settings')
        .flush(
          { type: 'validation_error', title: 'Validation failed', status: 422 },
          { status: 422, statusText: 'Unprocessable Entity' },
        );
      fixture.detectChanges();

      const element = fixture.nativeElement as HTMLElement;
      const idInput = element.querySelector<HTMLInputElement>('#passkey-rp-id-input');
      expect(idInput).not.toBeNull();
      expect(idInput!.value).toBe('not-this-host.example');
      expect(element.querySelector('app-settings-save-bar')).not.toBeNull();
    });

    it('offers Save only once a field is edited, and drops the edit on Reset', () => {
      const fixture = mount();
      flushInitial(fixture, { ...BASE_SETTINGS, passkeyRpId: 'reader.example.com' });

      expect(saveButton(fixture).disabled).toBe(true);

      type(fixture, '#passkey-rp-id-input', 'other.example.com');
      expect(saveButton(fixture).disabled).toBe(false);

      resetButton(fixture).click();
      fixture.detectChanges();

      const element = fixture.nativeElement as HTMLElement;
      expect(element.querySelector<HTMLInputElement>('#passkey-rp-id-input')!.value).toBe(
        'reader.example.com',
      );
      expect(saveButton(fixture).disabled).toBe(true);
      // No request at all: Reset is local, it never asks the server to undo.
      ctrl.verify();
    });

    it('a toggle never carries an unsaved text edit with it', () => {
      const fixture = mount();
      flushInitial(fixture, { ...BASE_SETTINGS, passkeyRpId: 'reader.example.com' });

      type(fixture, '#passkey-rp-id-input', 'typed-but-not-saved.example.com');
      (fixture.nativeElement as HTMLElement)
        .querySelectorAll<HTMLInputElement>('input[type="checkbox"]')[1]
        .dispatchEvent(new Event('change'));

      const testRequest = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(testRequest.request.body.passkeyRpId).toBe('reader.example.com');
      testRequest.flush({
        ...BASE_SETTINGS,
        passkeyRpId: 'reader.example.com',
        requireApproval: true,
      });
      fixture.detectChanges();

      // …and the edit is still in the field, waiting for Save.
      expect(
        (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
          '#passkey-rp-id-input',
        )!.value,
      ).toBe('typed-but-not-saved.example.com');
    });

    it('opens a confirm dialog quoting the invalidated count on a 409, and resends only on confirmation', () => {
      dialogStub.open.mockReturnValue({ closed: of(true) });
      const fixture = mount();
      flushInitial(fixture);

      const idInput = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = 'other.example.com';
      idInput.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      ctrl.expectOne('https://api.test/api/admin/settings').flush(
        {
          type: 'relying_party_change_requires_confirmation',
          title: 'Relying party change requires confirmation',
          status: 409,
          detail: 'Changing the passkey relying party id invalidates 3 enrolled passkey(s).',
          invalidatedPasskeyCount: 3,
        },
        { status: 409, statusText: 'Conflict' },
      );

      expect(dialogStub.open).toHaveBeenCalledWith(
        ConfirmDialogComponent,
        expect.objectContaining({
          role: 'alertdialog',
          panelClass: 'app-dialog',
          data: expect.objectContaining({
            message: expect.stringContaining('3'),
            danger: true,
          }),
        }),
      );

      const resent = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(resent.request.method).toBe('PUT');
      expect(resent.request.body).toEqual({
        ...BASE_UPDATE,
        passkeyRpId: 'other.example.com',
        invalidateExistingPasskeys: true,
      });
      resent.flush({
        ...BASE_SETTINGS,
        passkeyRpId: 'other.example.com',
        passkeyRpIdEffective: 'other.example.com',
      });
    });

    it('sends nothing when the invalidation confirmation is dismissed', () => {
      dialogStub.open.mockReturnValue({ closed: of(false) });
      const fixture = mount();
      flushInitial(fixture);

      const idInput = (fixture.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = 'other.example.com';
      idInput.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      ctrl.expectOne('https://api.test/api/admin/settings').flush(
        {
          type: 'relying_party_change_requires_confirmation',
          title: 'Relying party change requires confirmation',
          status: 409,
          detail: 'Changing the passkey relying party id invalidates 3 enrolled passkey(s).',
          invalidatedPasskeyCount: 3,
        },
        { status: 409, statusText: 'Conflict' },
      );

      expect(dialogStub.open).toHaveBeenCalledTimes(1);
      ctrl.expectNone('https://api.test/api/admin/settings');
    });

    it('renders the help disclosure closed on first render', () => {
      const fixture = mount();
      flushInitial(fixture);

      const details = (fixture.nativeElement as HTMLElement).querySelector(
        'app-disclosure details',
      );
      expect(details).not.toBeNull();
      expect((details as HTMLDetailsElement).open).toBe(false);
    });
  });
});
