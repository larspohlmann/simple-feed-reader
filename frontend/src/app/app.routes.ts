// src/app/app.routes.ts
import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [guestGuard],
    loadComponent: () => import('./auth/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'register',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./auth/register/register.component').then((m) => m.RegisterComponent),
  },
  {
    path: 'verify-email',
    loadComponent: () =>
      import('./auth/verify-email/verify-email.component').then((m) => m.VerifyEmailComponent),
  },
  {
    path: 'reset-password-request',
    canActivate: [guestGuard],
    loadComponent: () =>
      import('./auth/reset-request/reset-request.component').then((m) => m.ResetRequestComponent),
  },
  {
    path: 'reset-password',
    loadComponent: () =>
      import('./auth/reset-password/reset-password.component').then(
        (m) => m.ResetPasswordComponent,
      ),
  },
  {
    path: 'auth/callback',
    loadComponent: () =>
      import('./auth/oauth-callback/oauth-callback.component').then(
        (m) => m.OAuthCallbackComponent,
      ),
  },
  {
    path: 'settings',
    canActivate: [authGuard],
    loadChildren: () => import('./settings/settings.routes').then((m) => m.SETTINGS_ROUTES),
  },
  { path: 'admin/users', redirectTo: 'settings/admin/users' },
  { path: 'admin/catalog', redirectTo: 'settings/admin/catalog' },
  {
    path: 'discover',
    canActivate: [authGuard],
    loadComponent: () => import('./discover/discover.component').then((m) => m.DiscoverComponent),
  },
  {
    path: '',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./reader/reader-shell.component').then((m) => m.ReaderShellComponent),
  },
  { path: '**', redirectTo: '' },
];
