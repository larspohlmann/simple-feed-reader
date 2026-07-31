// src/app/app.config.spec.ts
import { TestBed } from '@angular/core/testing';
import { appConfig } from './app.config';
import { HttpLocaleWriter } from './core/http-locale-writer';
import { LOCALE_WRITER } from './core/locale-writer';

/**
 * `LOCALE_WRITER` defaults to a no-op (see `core/locale-writer.ts`) so most
 * of the app never needs `HttpClient` just to construct `LanguageService`.
 * `app.config.ts` is the one place that is supposed to override that default
 * with the real, `HttpClient`-backed writer for the running app -- if that
 * override is ever lost, the app silently keeps the no-op: languages apply
 * locally but never reach the account, with every other test still green.
 * This asserts the real application config, not a hand-built one, resolves
 * `LOCALE_WRITER` to `HttpLocaleWriter` specifically -- not merely to
 * *something* injectable, which the token's own `providedIn: 'root'` default
 * would already satisfy.
 */
describe('appConfig', () => {
  it('provides the HTTP-backed locale writer, not the no-op default', () => {
    TestBed.configureTestingModule({ providers: appConfig.providers });

    expect(TestBed.inject(LOCALE_WRITER)).toBeInstanceOf(HttpLocaleWriter);
  });
});
