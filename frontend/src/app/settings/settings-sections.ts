export type SettingsGroup = 'general' | 'admin';

export interface SettingsSection {
  /** Child route path under /settings — doubles as the routerLink target. */
  readonly path: string;
  /** Material Symbol name for <app-icon>. */
  readonly icon: string;
  readonly labelKey: string;
  readonly group: SettingsGroup;
  /** Opts the section out of the default content column width. */
  readonly wide?: boolean;
}

/** The one list both nav renderings (rail and hub) draw from. Adding a section
 *  means one entry here plus one route in settings.routes.ts — the shell stays
 *  untouched (#180's extensibility criterion). */
export const SETTINGS_SECTIONS: readonly SettingsSection[] = [
  { path: 'tags', icon: 'sell', labelKey: 'settings.tags.title', group: 'general' },
  { path: 'import', icon: 'import_export', labelKey: 'settings.opml.title', group: 'general' },
  { path: 'preferences', icon: 'tune', labelKey: 'settings.preferences', group: 'general' },
  { path: 'account', icon: 'person', labelKey: 'settings.account.title', group: 'general' },
  { path: 'ai', icon: 'smart_toy', labelKey: 'settings.ai.title', group: 'general' },
  { path: 'about', icon: 'info', labelKey: 'settings.about.title', group: 'general' },
  { path: 'admin/users', icon: 'shield_person', labelKey: 'admin.title', group: 'admin' },
  {
    path: 'admin/catalog',
    icon: 'category',
    labelKey: 'admin.catalog',
    group: 'admin',
    wide: true,
  },
  {
    path: 'admin/settings',
    icon: 'toggle_on',
    labelKey: 'settings.instance.title',
    group: 'admin',
  },
];
