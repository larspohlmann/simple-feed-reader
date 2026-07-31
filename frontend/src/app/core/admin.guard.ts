// src/app/core/admin.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router, UrlTree } from '@angular/router';
import { Observable, catchError, map, of } from 'rxjs';
import { AuthService } from './auth.service';

/** UX-only gate for the admin surface — the real guard is ROLE_ADMIN on
 *  ^/api/admin/ in security.yaml. On a deep link (no reader visited yet) the
 *  user may not be loaded; fetch it first, then decide. */
export const adminGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);
  const decide = (): boolean | UrlTree => (auth.isAdmin() ? true : router.createUrlTree(['/']));

  if (auth.user()) return decide();

  return auth.loadMe().pipe(
    map(() => decide()),
    catchError(() => of<boolean | UrlTree>(router.createUrlTree(['/']))),
  ) as Observable<boolean | UrlTree>;
};
