import { TranslocoService } from '@jsverse/transloco';

/** The active language's text for `key`, or null when the dictionary has none — for keys built from server codes. */
export function translationOf(i18n: TranslocoService, key: string): string | null {
  return Object.hasOwn(i18n.getTranslation(i18n.getActiveLang()), key) ? i18n.translate(key) : null;
}
