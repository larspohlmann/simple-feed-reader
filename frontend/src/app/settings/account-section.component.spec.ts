import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
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

  function mount(u: CurrentUser | null) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [provideRouter([]), { provide: AuthService, useValue: { user: () => u, logout } }],
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

  it('renders inside a settings card', () => {
    const f = mount(user);
    expect((f.nativeElement as HTMLElement).querySelector('app-settings-card')).not.toBeNull();
  });
});
