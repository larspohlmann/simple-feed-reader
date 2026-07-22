import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { AccountSectionComponent } from './account-section.component';
import { AuthService, CurrentUser } from '../core/auth.service';

const user: CurrentUser = {
  id: 1,
  email: 'me@x',
  roles: ['ROLE_USER'],
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
};

describe('AccountSectionComponent', () => {
  const logout = jest.fn();

  function mount(u: CurrentUser | null, admin = false) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: AuthService, useValue: { user: () => u, isAdmin: () => admin, logout } },
      ],
    });
    const f = TestBed.createComponent(AccountSectionComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => logout.mockReset());

  it('shows the email and a sign-out button', () => {
    const f = mount(user);
    expect((f.nativeElement as HTMLElement).textContent).toContain('me@x');
    (f.nativeElement.querySelector('.signout') as HTMLButtonElement).click();
    expect(logout).toHaveBeenCalled();
  });

  it('shows an Admin link only for admins', () => {
    expect(
      (mount(user, false).nativeElement as HTMLElement).querySelector('a[href="/admin/users"]'),
    ).toBeNull();
    expect(
      (mount(user, true).nativeElement as HTMLElement).querySelector('a[href="/admin/users"]'),
    ).not.toBeNull();
  });
});
