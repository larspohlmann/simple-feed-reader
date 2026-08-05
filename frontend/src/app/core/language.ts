// src/app/core/language.ts

/** The languages the UI ships translations for.
 *
 *  Adding one needs a matching edit in `deploy/strato/.htaccess`, whose
 *  cache-header rule names the dictionary files explicitly. Missing it costs no
 *  test and no build error — the new dictionary just goes uncached in
 *  production, which is what made boot fragile on mobile in the first place
 *  (#280). `.htaccess` cannot express "everything under i18n/" without
 *  directives Apache rejects in that context, hence the duplication.
 */
export type Lang = 'en' | 'de';

export const LANGS: readonly Lang[] = ['en', 'de'];
export const LANG_KEY = 'sfr.lang';

/**
 * The language every fallback path lands on: Transloco's `fallbackLang`, the
 * missing-key fallback translation, and the dictionary that ships inside the
 * bundle so booting can never depend on the network (#280).
 */
export const FALLBACK_LANG: Lang = 'en';

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
