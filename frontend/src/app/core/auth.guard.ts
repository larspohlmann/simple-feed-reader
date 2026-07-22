// src/app/core/auth.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { TokenStore } from './token.store';

export const authGuard: CanActivateFn = () => {
  const tokens = inject(TokenStore);
  return tokens.isAuthenticated() ? true : inject(Router).createUrlTree(['/login']);
};

export const guestGuard: CanActivateFn = () => {
  const tokens = inject(TokenStore);
  return tokens.isAuthenticated() ? inject(Router).createUrlTree(['/']) : true;
};
