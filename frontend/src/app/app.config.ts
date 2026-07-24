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
import { firstValueFrom } from 'rxjs';
import { routes } from './app.routes';
import { API_BASE_URL } from './core/api';
import { authInterceptor } from './core/auth.interceptor';
import { HttpTranslocoLoader } from './core/transloco-loader';
import { LanguageService } from './core/language.service';
import { environment } from '../environments/environment';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes),
    provideHttpClient(withInterceptors([authInterceptor])),
    { provide: API_BASE_URL, useValue: environment.apiBaseUrl },
    provideTransloco({
      config: {
        availableLangs: ['en', 'de'],
        defaultLang: 'en',
        fallbackLang: 'en',
        reRenderOnLangChange: true,
        prodMode: !isDevMode(),
        missingHandler: { logMissingKey: isDevMode(), useFallbackTranslation: true },
      },
      loader: HttpTranslocoLoader,
    }),
    // Resolve the persisted/detected language and preload its dictionary before the
    // first render, so the app never flashes English before switching to German.
    provideAppInitializer(() => {
      const language = inject(LanguageService); // constructing it sets the active lang
      const transloco = inject(TranslocoService);
      return firstValueFrom(transloco.load(language.lang()));
    }),
  ],
};
