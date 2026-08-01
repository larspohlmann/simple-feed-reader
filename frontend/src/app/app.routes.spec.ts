// src/app/app.routes.spec.ts
import { routes } from './app.routes';
import { guestGuard } from './core/auth.guard';
import { setupRedirectGuard } from './setup/setup.guard';

describe('routes', () => {
  const paths = routes.map((r) => r.path);

  it('exposes the exact paths the backend links to', () => {
    for (const p of [
      'login',
      'register',
      'verify-email',
      'reset-password-request',
      'reset-password',
      'auth/callback',
      '',
    ]) {
      expect(paths).toContain(p);
    }
  });

  it('lazy-loads the settings area as child routes', () => {
    const settings = routes.find((r) => r.path === 'settings');
    expect(settings?.loadChildren).toBeDefined();
  });

  it('redirects the pre-#180 admin urls into the settings area', () => {
    expect(routes.find((r) => r.path === 'admin/users')?.redirectTo).toBe('settings/admin/users');
    expect(routes.find((r) => r.path === 'admin/catalog')?.redirectTo).toBe(
      'settings/admin/catalog',
    );
  });

  it('loads mailEnabled before rendering reset-password-request, like login and register', () => {
    for (const path of ['login', 'register', 'reset-password-request']) {
      expect(routes.find((r) => r.path === path)?.canActivate).toEqual([
        setupRedirectGuard,
        guestGuard,
      ]);
    }
  });
});
