// src/app/core/auth.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { ReaderLocationService } from './reader-location.service';
import { TokenStore } from './token.store';

export const authGuard: CanActivateFn = (_, state) => {
  const tokens = inject(TokenStore);
  const readerLocation = inject(ReaderLocationService);
  if (tokens.isAuthenticated()) return true;
  readerLocation.rememberAttemptedReaderUrl(state.url);
  return inject(Router).createUrlTree(['/login']);
};

export const guestGuard: CanActivateFn = () => {
  const tokens = inject(TokenStore);
  return tokens.isAuthenticated() ? inject(Router).createUrlTree(['/']) : true;
};
