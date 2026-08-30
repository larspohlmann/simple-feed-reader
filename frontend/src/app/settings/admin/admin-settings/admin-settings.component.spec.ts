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
    const f = TestBed.createComponent(AdminSettingsComponent);
    f.detectChanges(); // ngOnInit → initial load
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  afterEach(() => ctrl.verify());

  function flushInitial(f: ReturnType<typeof mount>, settings: InstanceSettings = BASE_SETTINGS) {
    ctrl.expectOne('https://api.test/api/admin/settings').flush(settings);
    f.detectChanges();
  }

  const savebar = (f: ReturnType<typeof mount>) =>
    (f.nativeElement as HTMLElement).querySelector('app-settings-save-bar')!;
  const saveButton = (f: ReturnType<typeof mount>) =>
    savebar(f).querySelector<HTMLButtonElement>('app-button[variant="primary"] button')!;
  const resetButton = (f: ReturnType<typeof mount>) =>
    savebar(f).querySelector<HTMLButtonElement>('app-button[variant="ghost"] button')!;

  /** Text fields are dirty-tracked behind the save bar, so an edit is two
   *  steps: type, then Save. */
  function type(f: ReturnType<typeof mount>, selector: string, value: string) {
    const input = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(selector)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    f.detectChanges();
    return input;
  }

  it('loads the settings on init and renders both toggles', () => {
    const f = mount();
    flushInitial(f);

    const el = f.nativeElement as HTMLElement;
    const checkboxes = el.querySelectorAll('input[type="checkbox"]');
    expect(checkboxes.length).toBe(3);
  });

  it('disables the email-confirmation control and shows an explanation when mail is off', () => {
    const f = mount();
    flushInitial(f, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      mailEnabled: false,
    });

    const el = f.nativeElement as HTMLElement;
    const checkboxes = el.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
    const emailConfirmation = checkboxes[0];
    const approval = checkboxes[1];

    expect(emailConfirmation.checked).toBe(true);
    expect(emailConfirmation.disabled).toBe(true);
    expect(approval.disabled).toBe(false);
    expect(el.textContent).toContain('This instance sends no mail');
  });

  it('leaves the email-confirmation control enabled and shows no mailless explanation when mail is on', () => {
    const f = mount();
    flushInitial(f, { ...BASE_SETTINGS, requireEmailConfirmation: true, requireApproval: true });

    const el = f.nativeElement as HTMLElement;
    const emailConfirmation = el.querySelector<HTMLInputElement>('input[type="checkbox"]')!;
    expect(emailConfirmation.disabled).toBe(false);
    expect(el.textContent).not.toContain('This instance sends no mail');
  });

  it('toggling approval calls update and applies the response', () => {
    const f = mount();
    flushInitial(f, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      mailEnabled: false,
    });

    const el = f.nativeElement as HTMLElement;
    const approval = el.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')[1];
    approval.checked = false;
    approval.dispatchEvent(new Event('change'));

    const req = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({
      ...BASE_UPDATE,
      requireEmailConfirmation: true,
      requireApproval: false,
    });
    req.flush({
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: false,
      mailEnabled: false,
    });

    f.detectChanges();
    expect(f.componentInstance.requireApproval()).toBe(false);
  });

  /** #624 follow-up: the third toggle, round-tripped the same way `toggling
   *  approval calls update and applies the response` proves the second one. */
  it('toggling passkey sign-in calls update and applies the response', () => {
    const f = mount();
    flushInitial(f);

    const el = f.nativeElement as HTMLElement;
    const passkeyToggle = el.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')[2];
    expect(passkeyToggle.checked).toBe(true);
    passkeyToggle.checked = false;
    passkeyToggle.dispatchEvent(new Event('change'));

    const req = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ ...BASE_UPDATE, passkeySignInEnabled: false });
    req.flush({ ...BASE_SETTINGS, passkeySignInEnabled: false });

    f.detectChanges();
    expect(f.componentInstance.passkeySignInEnabled()).toBe(false);
  });

  it('surfaces an error banner with a retry when the load fails', () => {
    const f = mount();
    ctrl
      .expectOne('https://api.test/api/admin/settings')
      .flush(
        { type: 'about:blank', title: 'Down', status: 500 },
        { status: 500, statusText: 'Server Error' },
      );
    f.detectChanges();

    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('app-error-banner')).not.toBeNull();

    const retry = el.querySelector('[role="alert"] button') as HTMLButtonElement;
    retry.click();
    ctrl.expectOne('https://api.test/api/admin/settings').flush(BASE_SETTINGS);
  });

  it('renders both switches as settings rows in one group', () => {
    const f = mount();
    flushInitial(f);
    const el = f.nativeElement as HTMLElement;

    expect(el.querySelectorAll('app-settings-group').length).toBe(1);
    expect(el.querySelectorAll('app-settings-row app-toggle').length).toBe(3);
  });

  it('toggles the control when the visible label text is clicked, not only the switch', () => {
    const f = mount();
    flushInitial(f);
    const el = f.nativeElement as HTMLElement;

    const labels = el.querySelectorAll<HTMLLabelElement>('.row-title label');
    const checkboxes = el.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
    // Three toggle rows plus the text rows (publicBaseUrl, passkeyRpId, passkeyRpName).
    expect(labels.length).toBe(6);
    expect(labels[0].htmlFor).toBe(checkboxes[0].id);
    expect(labels[1].htmlFor).toBe(checkboxes[1].id);

    labels[1].click();
    f.detectChanges();

    const req = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(req.request.body).toEqual({ ...BASE_UPDATE, requireApproval: true });
    req.flush({ ...BASE_SETTINGS, requireApproval: true });
  });

  it('saving the public base URL sends it in the update and applies the response', () => {
    const f = mount();
    flushInitial(f, { ...BASE_SETTINGS, requireEmailConfirmation: true, requireApproval: true });

    const input = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
      '#public-base-url-input',
    )!;
    input.value = 'https://reader.example.ts.net/reader';
    input.dispatchEvent(new Event('input'));
    f.detectChanges();
    saveButton(f).click();

    const req = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({
      ...BASE_UPDATE,
      requireEmailConfirmation: true,
      requireApproval: true,
      publicBaseUrl: 'https://reader.example.ts.net/reader',
    });
    req.flush({
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      publicBaseUrl: 'https://reader.example.ts.net/reader',
    });
    f.detectChanges();
    expect(f.componentInstance.publicBaseUrl()).toBe('https://reader.example.ts.net/reader');
  });

  it('clearing the public base URL sends null', () => {
    const f = mount();
    flushInitial(f, {
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
      publicBaseUrl: 'https://old.example/',
    });

    const input = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
      '#public-base-url-input',
    )!;
    input.value = '   ';
    input.dispatchEvent(new Event('input'));
    f.detectChanges();
    saveButton(f).click();

    const req = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(req.request.body).toEqual({
      ...BASE_UPDATE,
      requireEmailConfirmation: true,
      requireApproval: true,
    });
    req.flush({
      ...BASE_SETTINGS,
      requireEmailConfirmation: true,
      requireApproval: true,
    });
  });

  describe('passkey relying-party fields', () => {
    it('renders both fields and round-trips a saved value', () => {
      const f = mount();
      flushInitial(f, {
        ...BASE_SETTINGS,
        passkeyRpId: 'reader.example.com',
        passkeyRpName: 'My Reader',
      });

      const el = f.nativeElement as HTMLElement;
      const idInput = el.querySelector<HTMLInputElement>('#passkey-rp-id-input')!;
      const nameInput = el.querySelector<HTMLInputElement>('#passkey-rp-name-input')!;

      expect(idInput.value).toBe('reader.example.com');
      expect(nameInput.value).toBe('My Reader');

      idInput.value = 'other.example.com';
      idInput.dispatchEvent(new Event('input'));
      f.detectChanges();
      saveButton(f).click();

      const req = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(req.request.body).toEqual({
        ...BASE_UPDATE,
        passkeyRpId: 'other.example.com',
        passkeyRpName: 'My Reader',
      });
      req.flush({
        ...BASE_SETTINGS,
        passkeyRpId: 'other.example.com',
        passkeyRpName: 'My Reader',
        passkeyRpIdEffective: 'other.example.com',
      });
      f.detectChanges();
      expect(f.componentInstance.passkeyRpId()).toBe('other.example.com');
    });

    it('sends an empty relying-party id as null, restoring the fallback', () => {
      const f = mount();
      flushInitial(f, { ...BASE_SETTINGS, passkeyRpId: 'reader.example.com' });

      const idInput = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = '   ';
      idInput.dispatchEvent(new Event('input'));
      f.detectChanges();
      saveButton(f).click();

      const req = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(req.request.body).toEqual({ ...BASE_UPDATE, passkeyRpId: null });
      req.flush(BASE_SETTINGS);
    });

    it('sends an empty relying-party name as null', () => {
      const f = mount();
      flushInitial(f, { ...BASE_SETTINGS, passkeyRpName: 'My Reader' });

      const nameInput = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-name-input',
      )!;
      nameInput.value = '';
      nameInput.dispatchEvent(new Event('input'));
      f.detectChanges();
      saveButton(f).click();

      const req = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(req.request.body).toEqual({ ...BASE_UPDATE, passkeyRpName: null });
      req.flush(BASE_SETTINGS);
    });

    it('uses passkeyRpIdEffective as the placeholder, not a hard-coded host', () => {
      const f = mount();
      flushInitial(f, { ...BASE_SETTINGS, passkeyRpIdEffective: 'reader.example.org' });

      const idInput = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      expect(idInput.placeholder).toBe('reader.example.org');
    });

    it("interpolates passkeyRpIdEffective into the field's description", () => {
      const f = mount();
      flushInitial(f, { ...BASE_SETTINGS, passkeyRpIdEffective: 'reader.example.org' });

      const el = f.nativeElement as HTMLElement;
      expect(el.textContent).toContain('reader.example.org');
    });

    it('renders a 422 validation message from the server', () => {
      const f = mount();
      flushInitial(f);

      const idInput = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = 'not-this-host.example';
      idInput.dispatchEvent(new Event('input'));
      f.detectChanges();
      saveButton(f).click();

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
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('app-error-banner')).not.toBeNull();
      // The server's per-field reason, not the shared 422 detail, which names
      // neither the field nor what is wrong with it.
      expect(el.textContent).toContain(
        'Must be the host, or a registrable parent domain of the host',
      );
      expect(el.textContent).not.toContain('One or more fields are invalid.');
    });

    it('keeps the form and the rejected edit on screen when a save fails', () => {
      const f = mount();
      flushInitial(f);

      type(f, '#passkey-rp-id-input', 'not-this-host.example');
      saveButton(f).click();
      ctrl
        .expectOne('https://api.test/api/admin/settings')
        .flush(
          { type: 'validation_error', title: 'Validation failed', status: 422 },
          { status: 422, statusText: 'Unprocessable Entity' },
        );
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      const idInput = el.querySelector<HTMLInputElement>('#passkey-rp-id-input');
      expect(idInput).not.toBeNull();
      expect(idInput!.value).toBe('not-this-host.example');
      expect(el.querySelector('app-settings-save-bar')).not.toBeNull();
    });

    it('offers Save only once a field is edited, and drops the edit on Reset', () => {
      const f = mount();
      flushInitial(f, { ...BASE_SETTINGS, passkeyRpId: 'reader.example.com' });

      expect(saveButton(f).disabled).toBe(true);

      type(f, '#passkey-rp-id-input', 'other.example.com');
      expect(saveButton(f).disabled).toBe(false);

      resetButton(f).click();
      f.detectChanges();

      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector<HTMLInputElement>('#passkey-rp-id-input')!.value).toBe(
        'reader.example.com',
      );
      expect(saveButton(f).disabled).toBe(true);
      // No request at all: Reset is local, it never asks the server to undo.
      ctrl.verify();
    });

    it('a toggle never carries an unsaved text edit with it', () => {
      const f = mount();
      flushInitial(f, { ...BASE_SETTINGS, passkeyRpId: 'reader.example.com' });

      type(f, '#passkey-rp-id-input', 'typed-but-not-saved.example.com');
      (f.nativeElement as HTMLElement)
        .querySelectorAll<HTMLInputElement>('input[type="checkbox"]')[1]
        .dispatchEvent(new Event('change'));

      const req = ctrl.expectOne('https://api.test/api/admin/settings');
      expect(req.request.body.passkeyRpId).toBe('reader.example.com');
      req.flush({ ...BASE_SETTINGS, passkeyRpId: 'reader.example.com', requireApproval: true });
      f.detectChanges();

      // …and the edit is still in the field, waiting for Save.
      expect(
        (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>('#passkey-rp-id-input')!
          .value,
      ).toBe('typed-but-not-saved.example.com');
    });

    it('opens a confirm dialog quoting the invalidated count on a 409, and resends only on confirmation', () => {
      dialogStub.open.mockReturnValue({ closed: of(true) });
      const f = mount();
      flushInitial(f);

      const idInput = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = 'other.example.com';
      idInput.dispatchEvent(new Event('input'));
      f.detectChanges();
      saveButton(f).click();

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
      const f = mount();
      flushInitial(f);

      const idInput = (f.nativeElement as HTMLElement).querySelector<HTMLInputElement>(
        '#passkey-rp-id-input',
      )!;
      idInput.value = 'other.example.com';
      idInput.dispatchEvent(new Event('input'));
      f.detectChanges();
      saveButton(f).click();

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
      const f = mount();
      flushInitial(f);

      const details = (f.nativeElement as HTMLElement).querySelector('app-disclosure details');
      expect(details).not.toBeNull();
      expect((details as HTMLDetailsElement).open).toBe(false);
    });
  });
});
