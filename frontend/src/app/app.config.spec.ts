// src/app/app.config.spec.ts
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { appConfig } from './app.config';
import { NavigationFailureReporter } from './core/navigation-failure';
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

  it('routes a real navigation error through the reporter, not straight to the boot surface', async () => {
    // A functional test on purpose: calling reporter.report() directly would
    // assert nothing about whether withNavigationErrorHandler is wired to it.
    // resetConfig installs a route whose lazy load rejects, which is what a
    // failed chunk does, and lets the real router raise the real event.
    const surface = document.createElement('div');
    surface.id = 'boot-error';
    surface.hidden = true;
    document.body.appendChild(surface);
    jest.spyOn(console, 'error').mockImplementation(() => undefined);

    TestBed.configureTestingModule({ providers: appConfig.providers });
    const reporter = TestBed.inject(NavigationFailureReporter);
    const router = TestBed.inject(Router);
    // Mid-session: a page is already on screen, so the banner is the right
    // surface and the full-page one would be a lie.
    reporter.noteNavigationSucceeded();
    router.resetConfig([
      { path: 'broken', loadComponent: () => Promise.reject(new Error('chunk failed')) },
    ]);

    await router.navigateByUrl('/broken').catch(() => undefined);

    expect(reporter.failed()).toBe(true);
    expect(surface.hasAttribute('hidden')).toBe(true);

    surface.remove();
    jest.restoreAllMocks();
  });
});
