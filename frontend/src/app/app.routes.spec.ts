// src/app/app.routes.spec.ts
import { routes } from './app.routes';

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
});
