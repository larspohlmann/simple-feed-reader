// src/app/app.routes.spec.ts
import { Route } from '@angular/router';
import { hasTranslation } from '../testing/translation-keys';
import { routes } from './app.routes';
import { guestGuard } from './core/auth.guard';
import { DYNAMIC_TITLE } from './core/translated-title.strategy';
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

  it('titles every routed page, so none can keep the title of the one before it', () => {
    for (const route of routes.filter(isRouted)) {
      expect(route.title).toBeDefined();
    }
  });

  it('titles pages by a key the dictionary holds, not by finished text', () => {
    for (const route of routes.filter(isRouted)) {
      if (route.title === DYNAMIC_TITLE) continue;
      expect(hasTranslation(String(route.title))).toBe(true);
    }
  });

  it('leaves the reader to title itself after the article or list on screen', () => {
    expect(routes.find((r) => r.path === '')?.title).toBe(DYNAMIC_TITLE);
  });
});

/** A route that puts a page on screen — as opposed to a redirect. */
function isRouted(route: Route): boolean {
  return route.loadComponent !== undefined || route.loadChildren !== undefined;
}
