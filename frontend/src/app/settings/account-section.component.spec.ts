// src/app/settings/account-section.component.spec.ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { Router, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AccountSectionComponent } from './account-section.component';
import { AuthService, CurrentUser } from '../core/auth.service';

const user: CurrentUser = {
  id: 1,
  email: 'me@x',
  roles: ['ROLE_USER'],
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
  locale: 'en',
  trialEndsAt: null,
  preferences: { scrapeFallbackEnabled: false },
};

const base = 'https://api.test';

describe('AccountSectionComponent', () => {
  let httpMock: HttpTestingController;
  let auth: AuthService;
  let logoutSpy: jest.SpyInstance;
  const dialogStub = { open: jest.fn() };
  const navigate = jest.fn();

  function mount(u: CurrentUser | null) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: base },
        { provide: Router, useValue: { navigate } },
        { provide: Dialog, useValue: dialogStub },
      ],
    });
    // The real AuthService, not a stub: `deleteAccount()` must issue a real
    // DELETE that HttpTestingController can intercept, exactly like
    // AdminApi.deleteUser does in admin-user-detail.component.spec.ts.
    auth = TestBed.inject(AuthService);
    auth.user.set(u);
    // Spied rather than left real: logout() also clears the token, resets
    // preferences and navigates -- none of that is this component's concern,
    // only whether it gets called once the delete succeeds.
    logoutSpy = jest.spyOn(auth, 'logout').mockReturnValue(undefined);
    httpMock = TestBed.inject(HttpTestingController);
    const f = TestBed.createComponent(AccountSectionComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    dialogStub.open.mockReset();
    navigate.mockReset();
  });

  afterEach(() => httpMock.verify());

  it('shows the email and a sign-out button', () => {
    const f = mount(user);
    expect((f.nativeElement as HTMLElement).textContent).toContain('me@x');
    (f.nativeElement.querySelector('.signout') as HTMLButtonElement).click();
    expect(logoutSpy).toHaveBeenCalled();
  });

  it('renders inside a settings card', () => {
    const f = mount(user);
    expect((f.nativeElement as HTMLElement).querySelector('app-settings-card')).not.toBeNull();
  });

  it('deletes the account and logs out once confirmed', () => {
    const f = mount(user);
    dialogStub.open.mockReturnValue({ closed: of(true) });

    f.componentInstance.confirmThenDelete();

    const request = httpMock.expectOne(`${base}/api/me`);
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(logoutSpy).toHaveBeenCalled();
  });

  it('does nothing when the dialog is dismissed', () => {
    const f = mount(user);
    dialogStub.open.mockReturnValue({ closed: of(false) });

    f.componentInstance.confirmThenDelete();

    httpMock.expectNone(`${base}/api/me`);
    expect(logoutSpy).not.toHaveBeenCalled();
  });

  it('passes the account email as the required confirmation text', () => {
    const f = mount(user);
    dialogStub.open.mockReturnValue({ closed: of(false) });

    f.componentInstance.confirmThenDelete();
    httpMock.expectNone(`${base}/api/me`);

    const [, config] = dialogStub.open.mock.calls.at(-1) as [
      unknown,
      { data: { requireText: string } },
    ];
    expect(config.data.requireText).toBe('me@x');
  });

  it('shows the problem detail in an error banner when the delete request fails', () => {
    const f = mount(user);
    dialogStub.open.mockReturnValue({ closed: of(true) });

    f.componentInstance.confirmThenDelete();

    httpMock
      .expectOne(`${base}/api/me`)
      .flush(
        { type: 'https://example.test/last_admin', title: 'Last admin', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    f.detectChanges();

    expect(logoutSpy).not.toHaveBeenCalled();
    expect((f.nativeElement as HTMLElement).textContent).toContain('Last admin');
  });
});
