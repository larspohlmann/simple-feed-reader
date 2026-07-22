import { TestBed } from '@angular/core/testing';
import { UrlTree, provideRouter } from '@angular/router';
import { firstValueFrom, isObservable, of, throwError } from 'rxjs';
import { adminGuard } from './admin.guard';
import { AuthService, CurrentUser } from './auth.service';

const admin: CurrentUser = {
  id: 1,
  email: 'a',
  roles: ['ROLE_ADMIN', 'ROLE_USER'],
  status: 'active',
  createdAt: 'x',
};
const plain: CurrentUser = { ...admin, roles: ['ROLE_USER'] };

function run() {
  return TestBed.runInInjectionContext(() =>
    adminGuard({} as never, { url: '/admin/users' } as never),
  );
}

describe('adminGuard', () => {
  let userSignal: () => CurrentUser | null;
  let loadMe: jest.Mock;
  let isAdmin: jest.Mock;

  beforeEach(() => {
    loadMe = jest.fn();
    isAdmin = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: {
            user: () => userSignal(),
            loadMe: () => loadMe(),
            isAdmin: () => isAdmin(),
          },
        },
      ],
    });
  });

  it('allows an already-loaded admin synchronously', () => {
    userSignal = () => admin;
    isAdmin.mockReturnValue(true);
    expect(run()).toBe(true);
  });

  it('redirects an already-loaded non-admin to /', () => {
    userSignal = () => plain;
    isAdmin.mockReturnValue(false);
    const res = run();
    expect(res instanceof UrlTree).toBe(true);
    expect((res as UrlTree).toString()).toBe('/');
  });

  it('loads the user first on a deep link, then allows an admin', async () => {
    userSignal = () => null;
    loadMe.mockReturnValue(of(admin));
    isAdmin.mockReturnValue(true);
    const res = run();
    expect(isObservable(res)).toBe(true);
    await expect(firstValueFrom(res as never)).resolves.toBe(true);
  });

  it('redirects to / when loadMe fails', async () => {
    userSignal = () => null;
    loadMe.mockReturnValue(throwError(() => new Error('401')));
    const res = run();
    const val = await firstValueFrom(res as never);
    expect(val instanceof UrlTree).toBe(true);
  });
});
