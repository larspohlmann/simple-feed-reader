// src/app/core/language.ts

/** The languages the UI ships translations for. */
export type Lang = 'en' | 'de';

export const LANGS: readonly Lang[] = ['en', 'de'];
export const LANG_KEY = 'sfr.lang';

/**
 * Pick the initial UI language from a persisted choice or, failing that, the
 * browser's preferred language. German for any `de*` tag (de, de-DE, de-AT, …),
 * English for everything else — the two locales we translate.
 */
export function detectLang(navigatorLanguage: string | null | undefined): Lang {
  return (navigatorLanguage ?? '').toLowerCase().startsWith('de') ? 'de' : 'en';
}

/** Narrow an arbitrary stored/incoming value to a supported language, or null. */
export function asLang(value: string | null | undefined): Lang | null {
  return value != null && (LANGS as readonly string[]).includes(value) ? (value as Lang) : null;
}
