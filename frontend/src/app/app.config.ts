// src/app/app.config.ts
import {
  ApplicationConfig,
  inject,
  isDevMode,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
  provideZoneChangeDetection,
} from '@angular/core';
import { TitleStrategy, provideRouter, withNavigationErrorHandler } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { TranslocoService, provideTransloco } from '@jsverse/transloco';
import { routes } from './app.routes';
import { API_BASE_URL } from './core/api';
import { authInterceptor } from './core/auth.interceptor';
import { preloadInitialLanguage } from './core/boot-language';
import { NavigationFailureReporter } from './core/navigation-failure';
import { startNavigationWatchdog } from './core/navigation-watchdog';
import { DIGEST_WRITER } from './core/digest-writer';
import { HttpDigestWriter } from './core/http-digest-writer';
import { HttpLocaleWriter } from './core/http-locale-writer';
import { HttpMagazineStyleWriter } from './core/http-magazine-style-writer';
import { HttpPreferencesWriter } from './core/http-preferences-writer';
import { HttpTranslocoLoader } from './core/transloco-loader';
import { TranslatedTitleStrategy } from './core/translated-title.strategy';
import { FALLBACK_LANG, LANGS } from './core/language';
import { LanguageService } from './core/language.service';
import { LOCALE_WRITER } from './core/locale-writer';
import { MAGAZINE_STYLE_WRITER } from './core/magazine-style-writer';
import { PREFERENCES_WRITER } from './core/preferences-writer';
import { environment } from '../environments/environment';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZoneChangeDetection({ eventCoalescing: true }),
    // A lazy route chunk can fail or stall exactly like the dictionary fetch
    // (#280) — Brave's resume-reload serves main.js from the immutable cache
    // but a chunk evicted from the HTTP cache, or new since the last release,
    // breaks on the reconnecting radio. A failure raises NavigationError and
    // lands here; a stall raises nothing at all, which is what the navigation
    // watchdog below exists for (#285). Both report to the same place, which
    // decides between the static surface and an in-app banner.
    provideRouter(
      routes,
      withNavigationErrorHandler((event) => inject(NavigationFailureReporter).report(event.error)),
    ),
    // Every navigation writes the document title, so a page can never keep the
    // title of the page before it.
    { provide: TitleStrategy, useExisting: TranslatedTitleStrategy },
    provideHttpClient(withInterceptors([authInterceptor])),
    { provide: API_BASE_URL, useValue: environment.apiBaseUrl },
    // LOCALE_WRITER defaults to a no-op (see locale-writer.ts) so most of the
    // app never needs HttpClient just to construct LanguageService; the running
    // app overrides it here with the real, HttpClient-backed writer.
    { provide: LOCALE_WRITER, useExisting: HttpLocaleWriter },
    // PREFERENCES_WRITER defaults to a no-op (see preferences-writer.ts) for the
    // same reason LOCALE_WRITER does; the running app overrides it here too.
    { provide: PREFERENCES_WRITER, useExisting: HttpPreferencesWriter },
    { provide: MAGAZINE_STYLE_WRITER, useExisting: HttpMagazineStyleWriter },
    // DIGEST_WRITER defaults to a no-op (see digest-writer.ts) for the same
    // reason LOCALE_WRITER does; the running app overrides it here too.
    { provide: DIGEST_WRITER, useExisting: HttpDigestWriter },
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
    // Must run in an injection context, and only once the router exists.
    provideAppInitializer(() => startNavigationWatchdog()),
  ],
};
