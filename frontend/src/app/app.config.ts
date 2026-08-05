// src/app/app.config.ts
import {
  ApplicationConfig,
  inject,
  isDevMode,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
  provideZoneChangeDetection,
} from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { TranslocoService, provideTransloco } from '@jsverse/transloco';
import { routes } from './app.routes';
import { API_BASE_URL } from './core/api';
import { authInterceptor } from './core/auth.interceptor';
import { preloadInitialLanguage } from './core/boot-language';
import { HttpLocaleWriter } from './core/http-locale-writer';
import { HttpPreferencesWriter } from './core/http-preferences-writer';
import { HttpTranslocoLoader } from './core/transloco-loader';
import { FALLBACK_LANG, LANGS } from './core/language';
import { LanguageService } from './core/language.service';
import { LOCALE_WRITER } from './core/locale-writer';
import { PREFERENCES_WRITER } from './core/preferences-writer';
import { environment } from '../environments/environment';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes),
    provideHttpClient(withInterceptors([authInterceptor])),
    { provide: API_BASE_URL, useValue: environment.apiBaseUrl },
    // LOCALE_WRITER defaults to a no-op (see locale-writer.ts) so most of the
    // app never needs HttpClient just to construct LanguageService; the running
    // app overrides it here with the real, HttpClient-backed writer.
    { provide: LOCALE_WRITER, useExisting: HttpLocaleWriter },
    // PREFERENCES_WRITER defaults to a no-op (see preferences-writer.ts) for the
    // same reason LOCALE_WRITER does; the running app overrides it here too.
    { provide: PREFERENCES_WRITER, useExisting: HttpPreferencesWriter },
    provideTransloco({
      config: {
        availableLangs: [...LANGS],
        defaultLang: FALLBACK_LANG,
        fallbackLang: FALLBACK_LANG,
        reRenderOnLangChange: true,
        prodMode: !isDevMode(),
        missingHandler: { logMissingKey: isDevMode(), useFallbackTranslation: true },
      },
      loader: HttpTranslocoLoader,
    }),
    // Resolve the persisted/detected language and preload its dictionary before
    // the first render, so the app never flashes English before switching to
    // German. Bounded and non-fatal (#280): a failed or stalled dictionary
    // request falls back to the bundled English instead of rejecting bootstrap
    // and leaving a permanently blank page.
    provideAppInitializer(() => {
      const language = inject(LanguageService); // constructing it sets the active lang
      const transloco = inject(TranslocoService);
      return preloadInitialLanguage(transloco, language.lang());
    }),
  ],
};
