/** The languages the UI ships translations for.
 *
 *  Adding one needs a matching edit in `deploy/strato/.htaccess`, whose
 *  cache-header rule names the dictionary files explicitly: `.htaccess` cannot
 *  express "everything under i18n/" without directives Apache rejects in that
 *  context (#280).
 */
export const LANGS = ['en', 'de', 'fr', 'es'] as const;
export type Lang = (typeof LANGS)[number];
export const LANG_KEY = 'sfr.lang';

/**
 * The language every fallback path lands on: Transloco's `fallbackLang`, the
 * missing-key fallback translation, and the dictionary that ships inside the
 * bundle so booting can never depend on the network (#280).
 */
export const FALLBACK_LANG: Lang = 'en';

/** Narrow an arbitrary stored/incoming value to a supported language, or null. */
export function asLang(value: string | null | undefined): Lang | null {
  return value != null && (LANGS as readonly string[]).includes(value) ? (value as Lang) : null;
}

/**
 * Pick the initial UI language from a persisted choice or, failing that, the
 * browser's preferred language: the tag's primary subtag when we translate it
 * (de-AT, fr-CA, es-419, …), English for everything else.
 */
export function detectLang(navigatorLanguage: string | null | undefined): Lang {
  const primarySubtag = (navigatorLanguage ?? '').toLowerCase().split('-')[0];
  return asLang(primarySubtag) ?? FALLBACK_LANG;
}
