export type ThemeMode = 'light' | 'dark' | 'system';

export type ResolvedTheme = Exclude<ThemeMode, 'system'>;

/** Registered themes. 5a ships Graphite; a new theme adds one SCSS file and one
 *  entry here — no component changes. */
export const THEMES = [{ id: 'graphite', label: 'Graphite' }] as const;
