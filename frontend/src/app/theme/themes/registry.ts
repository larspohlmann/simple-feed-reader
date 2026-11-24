export type ThemeMode = 'light' | 'dark' | 'system';

export type ResolvedTheme = Exclude<ThemeMode, 'system'>;

/** A new theme adds an entry here, its SCSS file, a check in `index.html`'s inline script and,
 *  for its own face, `@font-face` rules in `_fonts.scss`; no component changes. */
export const THEMES = ['graphite', 'classic'] as const;

export type ThemeName = (typeof THEMES)[number];

export const DEFAULT_THEME_NAME: ThemeName = 'graphite';
