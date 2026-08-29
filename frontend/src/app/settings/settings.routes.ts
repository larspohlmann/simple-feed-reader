// src/app/settings/settings.routes.ts
import { Routes } from '@angular/router';
import { adminGuard } from '../core/admin.guard';
import { sectionLabelKey } from './settings-sections';

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
        path: 'organise',
        title: sectionLabelKey('organise'),
        loadComponent: () =>
          import('./organise/organise-section.component').then((m) => m.OrganiseSectionComponent),
      },
      // #714: Organise replaced the Tags page. The old path stays as a forward
      // for stale bookmarks -- no section entry, no title, it is not a page.
      { path: 'tags', redirectTo: 'organise', pathMatch: 'full' },
      {
        path: 'import',
        title: sectionLabelKey('import'),
        loadComponent: () =>
          import('./import-section.component').then((m) => m.ImportSectionComponent),
      },
      {
        path: 'preferences',
        title: sectionLabelKey('preferences'),
        loadComponent: () =>
          import('./preferences-section.component').then((m) => m.PreferencesSectionComponent),
      },
      {
        path: 'email',
        title: sectionLabelKey('email'),
        loadComponent: () =>
          import('./email-section.component').then((m) => m.EmailSectionComponent),
      },
      {
        path: 'account',
        title: sectionLabelKey('account'),
        loadComponent: () =>
          import('./account-section.component').then((m) => m.AccountSectionComponent),
      },
      {
        path: 'ai',
        title: sectionLabelKey('ai'),
        loadComponent: () => import('./ai-section.component').then((m) => m.AiSectionComponent),
      },
      {
        path: 'about',
        title: sectionLabelKey('about'),
        loadComponent: () =>
          import('./about-section.component').then((m) => m.AboutSectionComponent),
      },
      {
        path: 'admin/users',
        title: sectionLabelKey('admin/users'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-users.component').then((m) => m.AdminUsersComponent),
      },
      {
        path: 'admin/users/:id',
        title: 'admin.detail.title',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-user-detail.component').then((m) => m.AdminUserDetailComponent),
      },
      {
        path: 'admin/catalog',
        title: sectionLabelKey('admin/catalog'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/admin-catalog.component').then((m) => m.AdminCatalogComponent),
      },
      {
        path: 'admin/settings',
        title: sectionLabelKey('admin/settings'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/admin-settings/admin-settings.component').then(
            (m) => m.AdminSettingsComponent,
          ),
      },
      {
        path: 'admin/proxy',
        title: sectionLabelKey('admin/proxy'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/proxy/proxy-section.component').then((m) => m.ProxySectionComponent),
      },
    ],
  },
];
