import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { API_BASE_URL } from '../../../core/api';
import { AdminSettingsComponent } from './admin-settings.component';
import { InstanceSettings } from './admin-settings-api';

describe('AdminSettingsComponent', () => {
  let ctrl: HttpTestingController;

  function mount() {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    const f = TestBed.createComponent(AdminSettingsComponent);
    f.detectChanges(); // ngOnInit → initial load
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  afterEach(() => ctrl.verify());

  function flushInitial(f: ReturnType<typeof mount>, settings: InstanceSettings) {
    ctrl.expectOne('https://api.test/api/admin/settings').flush(settings);
    f.detectChanges();
  }

  it('loads the settings on init and renders both toggles', () => {
    const f = mount();
    flushInitial(f, { requireEmailConfirmation: false, requireApproval: false, mailEnabled: true });

    const el = f.nativeElement as HTMLElement;
    const checkboxes = el.querySelectorAll('input[type="checkbox"]');
    expect(checkboxes.length).toBe(2);
  });

  it('disables the email-confirmation control and shows an explanation when mail is off', () => {
    const f = mount();
    flushInitial(f, { requireEmailConfirmation: true, requireApproval: true, mailEnabled: false });

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
    flushInitial(f, { requireEmailConfirmation: true, requireApproval: true, mailEnabled: true });

    const el = f.nativeElement as HTMLElement;
    const emailConfirmation = el.querySelector<HTMLInputElement>('input[type="checkbox"]')!;
    expect(emailConfirmation.disabled).toBe(false);
    expect(el.textContent).not.toContain('This instance sends no mail');
  });

  it('toggling approval calls update and applies the response', () => {
    const f = mount();
    flushInitial(f, { requireEmailConfirmation: true, requireApproval: true, mailEnabled: false });

    const el = f.nativeElement as HTMLElement;
    const approval = el.querySelectorAll<HTMLInputElement>('input[type="checkbox"]')[1];
    approval.checked = false;
    approval.dispatchEvent(new Event('change'));

    const req = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(req.request.method).toBe('PUT');
    expect(req.request.body).toEqual({ requireEmailConfirmation: true, requireApproval: false });
    req.flush({ requireEmailConfirmation: true, requireApproval: false, mailEnabled: false });

    f.detectChanges();
    expect(f.componentInstance.requireApproval()).toBe(false);
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
    ctrl
      .expectOne('https://api.test/api/admin/settings')
      .flush({ requireEmailConfirmation: false, requireApproval: false, mailEnabled: true });
  });

  it('renders both switches as settings rows in one group', () => {
    const f = mount();
    flushInitial(f, { requireEmailConfirmation: false, requireApproval: false, mailEnabled: true });
    const el = f.nativeElement as HTMLElement;

    expect(el.querySelectorAll('app-settings-group').length).toBe(1);
    expect(el.querySelectorAll('app-settings-row app-toggle').length).toBe(2);
  });

  it('toggles the control when the visible label text is clicked, not only the switch', () => {
    const f = mount();
    flushInitial(f, { requireEmailConfirmation: false, requireApproval: false, mailEnabled: true });
    const el = f.nativeElement as HTMLElement;

    const labels = el.querySelectorAll<HTMLLabelElement>('.row-title label');
    const checkboxes = el.querySelectorAll<HTMLInputElement>('input[type="checkbox"]');
    expect(labels.length).toBe(2);
    expect(labels[0].htmlFor).toBe(checkboxes[0].id);
    expect(labels[1].htmlFor).toBe(checkboxes[1].id);

    labels[1].click();
    f.detectChanges();

    const req = ctrl.expectOne('https://api.test/api/admin/settings');
    expect(req.request.body).toEqual({ requireEmailConfirmation: false, requireApproval: true });
    req.flush({ requireEmailConfirmation: false, requireApproval: true, mailEnabled: true });
  });
});
