// src/app/settings/settings.routes.ts
import { Routes } from '@angular/router';
import { adminGuard } from '../core/admin.guard';

/** Children of /settings. Every section is lazy; the admin pair repeats the
 *  adminGuard because the parent authGuard only proves a session, not a role. */
export const SETTINGS_ROUTES: Routes = [
  {
    path: '',
    loadComponent: () => import('./settings-shell.component').then((m) => m.SettingsShellComponent),
    children: [
      {
        path: '',
        loadComponent: () => import('./settings-hub.component').then((m) => m.SettingsHubComponent),
      },
      {
        path: 'tags',
        loadComponent: () => import('./tags-section.component').then((m) => m.TagsSectionComponent),
      },
      {
        path: 'import',
        loadComponent: () => import('./opml-section.component').then((m) => m.OpmlSectionComponent),
      },
      {
        path: 'preferences',
        loadComponent: () =>
          import('./preferences-section.component').then((m) => m.PreferencesSectionComponent),
      },
      {
        path: 'account',
        loadComponent: () =>
          import('./account-section.component').then((m) => m.AccountSectionComponent),
      },
      {
        path: 'about',
        loadComponent: () =>
          import('./about-section.component').then((m) => m.AboutSectionComponent),
      },
      {
        path: 'admin/users',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-users.component').then((m) => m.AdminUsersComponent),
      },
      {
        path: 'admin/users/:id',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-user-detail.component').then((m) => m.AdminUserDetailComponent),
      },
      {
        path: 'admin/catalog',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-catalog.component').then((m) => m.AdminCatalogComponent),
      },
    ],
  },
];
