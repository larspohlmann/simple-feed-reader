import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';
import { ReaderLocationService } from './reader-location.service';
import { TokenStore } from './token.store';

/** Every signed-in route passes here, so this starts the one app-wide user load; it never
 *  waits for it, so a failing /api/me leaves the route rendering as before. */
export const authGuard: CanActivateFn = (_, state) => {
  const tokens = inject(TokenStore);
  const readerLocation = inject(ReaderLocationService);
  if (tokens.isAuthenticated()) {
    inject(AuthService)
      .ensureUserLoaded()
      .subscribe({ error: () => undefined });
    return true;
  }
  readerLocation.rememberAttemptedReaderUrl(state.url);
  return inject(Router).createUrlTree(['/login']);
};

export const guestGuard: CanActivateFn = () => {
  const tokens = inject(TokenStore);
  return tokens.isAuthenticated() ? inject(Router).createUrlTree(['/']) : true;
};
