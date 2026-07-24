// src/testing/transloco-testing.ts
import { TranslocoTestingModule, TranslocoTestingOptions } from '@jsverse/transloco';
import en from '../../public/i18n/en.json';
import de from '../../public/i18n/de.json';

/**
 * Drop-in Transloco provider for component specs. Loads the real shipped
 * dictionaries so tests assert against the actual English UI strings (default
 * lang `en`), and can switch to `de` to check translated rendering.
 */
export function provideTranslocoTesting(options: TranslocoTestingOptions = {}) {
  return TranslocoTestingModule.forRoot({
    langs: { en, de },
    translocoConfig: {
      availableLangs: ['en', 'de'],
      defaultLang: 'en',
      reRenderOnLangChange: true,
    },
    preloadLangs: true,
    ...options,
  });
}
