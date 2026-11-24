import { THEMES, ThemeName } from '../../theme/themes/registry';

export const THEME_NAMES: readonly ThemeName[] = THEMES;

/** The per-device paint cache; the account is the record. */
export const THEME_NAME_KEY = 'sfr.themeName';
