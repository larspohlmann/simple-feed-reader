import { Routes } from '@angular/router';
import { authGuard, guestGuard } from './core/auth/auth.guard';
import { DYNAMIC_TITLE } from './core/i18n/translated-title.strategy';
import { readerMatcher } from './reader/reader-matcher';
import { requireSetupGuard, setupRedirectGuard } from './setup/setup.guard';

export const routes: Routes = [
  {
    path: 'login',
    title: 'auth.login.title',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () =>
      import('./auth/login/login.component').then((module) => module.LoginComponent),
  },
  {
    path: 'register',
    title: 'auth.register.title',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () =>
      import('./auth/register/register.component').then((module) => module.RegisterComponent),
  },
  {
    path: 'setup',
    title: 'setup.title',
    canActivate: [requireSetupGuard],
    loadComponent: () => import('./setup/setup.component').then((module) => module.SetupComponent),
  },
  {
    path: 'verify-email',
    title: 'auth.verify.title',
    loadComponent: () =>
      import('./auth/verify-email/verify-email.component').then(
        (module) => module.VerifyEmailComponent,
      ),
  },
  {
    path: 'reset-password-request',
    title: 'auth.reset.requestTitle',
    canActivate: [setupRedirectGuard, guestGuard],
    loadComponent: () =>
      import('./auth/reset-request/reset-request.component').then(
        (module) => module.ResetRequestComponent,
      ),
  },
  {
    path: 'reset-password',
    title: 'auth.reset.newTitle',
    loadComponent: () =>
      import('./auth/reset-password/reset-password.component').then(
        (module) => module.ResetPasswordComponent,
      ),
  },
  {
    path: 'auth/callback',
    title: 'auth.oauth.title',
    loadComponent: () =>
      import('./auth/oauth-callback/oauth-callback.component').then(
        (module) => module.OAuthCallbackComponent,
      ),
  },
  {
    path: 'settings',
    title: 'settings.title',
    canActivate: [authGuard],
    loadChildren: () =>
      import('./settings/settings.routes').then((module) => module.SETTINGS_ROUTES),
  },
  { path: 'admin/users', redirectTo: 'settings/admin/users' },
  { path: 'admin/catalog', redirectTo: 'settings/admin/catalog' },
  {
    path: 'discover',
    title: 'discover.title',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./discover/discover.component').then((module) => module.DiscoverComponent),
  },
  {
    // The reader owns the root URL and the saved-search paths through one config,
    // so moving between a list and a saved search never tears the shell down.
    matcher: readerMatcher,
    title: DYNAMIC_TITLE,
    canActivate: [authGuard],
    loadComponent: () =>
      import('./reader/reader-shell.component').then((module) => module.ReaderShellComponent),
  },
  { path: '**', redirectTo: '' },
];
