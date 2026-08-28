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
  preferences: {
    scrapeFallbackEnabled: false,
    digest: { enabled: false, cadence: 'daily', sendHour: 8, weekday: 1, timezone: 'UTC' },
  },
  ai: { ready: false, model: null },
  mail: { enabled: true },
  emailVerified: true,
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
    const el = f.nativeElement as HTMLElement;
    expect(el.textContent).toContain('me@x');
    // The sign-out button carries no class hook of its own, so it is found by
    // its own label rather than by DOM position among the two `.actions`
    // blocks (account and danger zone both have one).
    const buttons = Array.from(el.querySelectorAll('button'));
    const signOut = buttons.find((button) => button.textContent?.includes('Sign out'));
    (signOut as HTMLButtonElement).click();
    expect(logoutSpy).toHaveBeenCalled();
  });

  it('renders the account and the danger zone as separate settings groups', () => {
    const f = mount(user);
    const el = f.nativeElement as HTMLElement;

    expect(el.querySelectorAll('app-settings-group').length).toBe(2);
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

    // The real document AccountDeleter's guard sends, verbatim from
    // LastAdminException / ApiProblem::toArray() (backend/src/Exception/
    // LastAdminException.php): `type` is a bare slug, never a URL -- see
    // ApiProblem's own docblock -- and `detail` is present, which is the
    // half of `error.detail || error.title` production actually renders.
    httpMock.expectOne(`${base}/api/me`).flush(
      {
        type: 'last_admin',
        title: 'Last administrator',
        status: 409,
        detail: 'This is the only administrator account. Promote another account first.',
      },
      { status: 409, statusText: 'Conflict' },
    );
    f.detectChanges();

    expect(logoutSpy).not.toHaveBeenCalled();
    expect((f.nativeElement as HTMLElement).textContent).toContain(
      'This is the only administrator account. Promote another account first.',
    );
  });

  it('falls back to the problem title when the response has no detail', () => {
    const f = mount(user);
    dialogStub.open.mockReturnValue({ closed: of(true) });

    f.componentInstance.confirmThenDelete();

    // No `detail` field at all -- exercises the `|| error.title` half of the
    // template expression, which the fixture above never touches.
    httpMock
      .expectOne(`${base}/api/me`)
      .flush(
        { type: 'about:blank', title: 'Something went wrong', status: 500 },
        { status: 500, statusText: 'Internal Server Error' },
      );
    f.detectChanges();

    expect(logoutSpy).not.toHaveBeenCalled();
    expect((f.nativeElement as HTMLElement).textContent).toContain('Something went wrong');
  });
});
