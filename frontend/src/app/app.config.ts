// src/app/app.config.ts
import {
  ApplicationConfig,
  inject,
  isDevMode,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
  provideZoneChangeDetection,
} from '@angular/core';
import { provideRouter, withNavigationErrorHandler } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { TranslocoService, provideTransloco } from '@jsverse/transloco';
import { routes } from './app.routes';
import { API_BASE_URL } from './core/api';
import { authInterceptor } from './core/auth.interceptor';
import { preloadInitialLanguage } from './core/boot-language';
import { revealBootErrorSurface } from './core/boot-error-surface';
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
    // A lazy route chunk can fail or stall exactly like the dictionary fetch
    // (#280) — Brave's resume-reload serves main.js from the immutable cache
    // but a chunk evicted from the HTTP cache, or new since the last release,
    // stalls on the reconnecting radio. The router then leaves the outlet
    // permanently empty, which looks identical to a rejected bootstrap, so it
    // gets the same static surface. This handler must not rely on anything
    // the bundle still needs to fetch: by the time it fires, a chunk has
    // already failed, so revealBootErrorSurface is deliberately DI-free.
    provideRouter(
      routes,
      withNavigationErrorHandler((event) => revealBootErrorSurface(event.error)),
    ),
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
