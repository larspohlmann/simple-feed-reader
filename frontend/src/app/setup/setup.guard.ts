// src/app/setup/setup.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';
import { SetupService } from './setup.service';

/** On login/register: while the instance has no admin, force the operator to the
 *  setup screen. If the status call fails, fail open — do not trap the user. */
export const setupRedirectGuard: CanActivateFn = () => {
  const setup = inject(SetupService);
  const router = inject(Router);
  return setup.ensureLoaded().pipe(
    map((needsSetup) => (needsSetup ? router.createUrlTree(['/setup']) : true)),
    catchError(() => of(true)),
  );
};

/** On /setup: once an admin exists the wizard is over — send the user to login. */
export const requireSetupGuard: CanActivateFn = () => {
  const setup = inject(SetupService);
  const router = inject(Router);
  return setup.ensureLoaded().pipe(
    map((needsSetup) => (needsSetup ? true : router.createUrlTree(['/login']))),
    catchError(() => of(router.createUrlTree(['/login']))),
  );
};
