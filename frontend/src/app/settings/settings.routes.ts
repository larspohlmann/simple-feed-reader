import { Routes } from '@angular/router';
import { adminGuard } from '../core/auth/admin.guard';
import { sectionLabelKey } from './settings-sections';

/** Children of /settings. Every section is lazy; the admin pair repeats the
 *  adminGuard because the parent authGuard only proves a session, not a role. */
export const SETTINGS_ROUTES: Routes = [
  {
    path: '',
    loadComponent: () =>
      import('./settings-shell.component').then((module) => module.SettingsShellComponent),
    children: [
      {
        path: '',
        loadComponent: () =>
          import('./settings-hub.component').then((module) => module.SettingsHubComponent),
      },
      {
        path: 'organise',
        title: sectionLabelKey('organise'),
        loadComponent: () =>
          import('./organise/organise-section.component').then(
            (module) => module.OrganiseSectionComponent,
          ),
      },
      // #714: Organise replaced the Tags page. The old path stays as a forward
      // for stale bookmarks -- no section entry, no title, it is not a page.
      { path: 'tags', redirectTo: 'organise', pathMatch: 'full' },
      {
        path: 'import',
        title: sectionLabelKey('import'),
        loadComponent: () =>
          import('./import/import-section.component').then(
            (module) => module.ImportSectionComponent,
          ),
      },
      {
        path: 'preferences',
        title: sectionLabelKey('preferences'),
        loadComponent: () =>
          import('./preferences/preferences-section.component').then(
            (module) => module.PreferencesSectionComponent,
          ),
      },
      {
        path: 'email',
        title: sectionLabelKey('email'),
        loadComponent: () =>
          import('./account/email-section.component').then(
            (module) => module.EmailSectionComponent,
          ),
      },
      {
        path: 'account',
        title: sectionLabelKey('account'),
        loadComponent: () =>
          import('./account/account-section.component').then(
            (module) => module.AccountSectionComponent,
          ),
      },
      {
        path: 'ai',
        title: sectionLabelKey('ai'),
        loadComponent: () =>
          import('./ai/ai-section.component').then((module) => module.AiSectionComponent),
      },
      { path: 'profile', redirectTo: 'ai', pathMatch: 'full' },
      {
        path: 'about',
        title: sectionLabelKey('about'),
        loadComponent: () =>
          import('./about/about-section.component').then((module) => module.AboutSectionComponent),
      },
      {
        path: 'admin/users',
        title: sectionLabelKey('admin/users'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/users/admin-users.component').then(
            (module) => module.AdminUsersComponent,
          ),
      },
      {
        path: 'admin/users/:id',
        title: 'admin.detail.title',
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/users/admin-user-detail.component').then(
            (module) => module.AdminUserDetailComponent,
          ),
      },
      {
        path: 'admin/catalog',
        title: sectionLabelKey('admin/catalog'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('../admin/catalog/admin-catalog.component').then(
            (module) => module.AdminCatalogComponent,
          ),
      },
      {
        path: 'admin/settings',
        title: sectionLabelKey('admin/settings'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/admin-settings/admin-settings.component').then(
            (module) => module.AdminSettingsComponent,
          ),
      },
      {
        path: 'admin/proxy',
        title: sectionLabelKey('admin/proxy'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/proxy/proxy-section.component').then(
            (module) => module.ProxySectionComponent,
          ),
      },
      {
        path: 'admin/grafana',
        title: sectionLabelKey('admin/grafana'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/grafana/grafana-section.component').then(
            (module) => module.GrafanaSectionComponent,
          ),
      },
      {
        path: 'admin/mail',
        title: sectionLabelKey('admin/mail'),
        canActivate: [adminGuard],
        loadComponent: () =>
          import('./admin/mail/mail-section.component').then(
            (module) => module.MailSectionComponent,
          ),
      },
    ],
  },
];
