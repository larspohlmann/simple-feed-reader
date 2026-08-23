// src/app/app.routes.ts
import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth.guard';
import { DYNAMIC_TITLE } from './core/translated-title.strategy';
import { requireSetupGuard, setupRedirectGuard } from './setup/setup.guard';

export const routes: Routes = [
  {
    path: 'login',
    title: 'auth.login.title',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () => import('./auth/login/login.component').then((m) => m.LoginComponent),
  },
  {
    path: 'register',
    title: 'auth.register.title',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () =>
      import('./auth/register/register.component').then((m) => m.RegisterComponent),
  },
  {
    path: 'setup',
    title: 'setup.title',
    canActivate: [requireSetupGuard],
    loadComponent: () => import('./setup/setup.component').then((m) => m.SetupComponent),
  },
  {
    path: 'verify-email',
    title: 'auth.verify.title',
    loadComponent: () =>
      import('./auth/verify-email/verify-email.component').then((m) => m.VerifyEmailComponent),
  },
  {
    path: 'reset-password-request',
    title: 'auth.reset.requestTitle',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () =>
      import('./auth/reset-request/reset-request.component').then((m) => m.ResetRequestComponent),
  },
  {
    path: 'reset-password',
    title: 'auth.reset.newTitle',
    loadComponent: () =>
      import('./auth/reset-password/reset-password.component').then(
        (m) => m.ResetPasswordComponent,
      ),
  },
  {
    path: 'auth/callback',
    title: 'auth.oauth.title',
    loadComponent: () =>
      import('./auth/oauth-callback/oauth-callback.component').then(
        (m) => m.OAuthCallbackComponent,
      ),
  },
  {
    path: 'settings',
    title: 'settings.title',
    canActivate: [authGuard],
    loadChildren: () => import('./settings/settings.routes').then((m) => m.SETTINGS_ROUTES),
  },
  { path: 'admin/users', redirectTo: 'settings/admin/users' },
  { path: 'admin/catalog', redirectTo: 'settings/admin/catalog' },
  {
    path: 'discover',
    title: 'discover.title',
    canActivate: [authGuard],
    loadComponent: () => import('./discover/discover.component').then((m) => m.DiscoverComponent),
  },
  {
    // No title: the reader names the tab after the open article or the selected
    // list, and keeps doing so across the query-parameter navigations that
    // switch between them.
    path: '',
    data: DYNAMIC_TITLE,
    canActivate: [authGuard],
    loadComponent: () =>
      import('./reader/reader-shell.component').then((m) => m.ReaderShellComponent),
  },
  { path: '**', redirectTo: '' },
];
