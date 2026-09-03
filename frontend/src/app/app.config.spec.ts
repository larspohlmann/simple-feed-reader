import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { appConfig } from './app.config';
import { NavigationFailureReporter } from './core/navigation-failure';
import { HttpLocaleWriter } from './core/http-locale-writer';
import { LOCALE_WRITER } from './core/locale-writer';

/**
 * LOCALE_WRITER defaults to a no-op, so app.config.ts must override it with
 * the real HttpClient-backed writer -- otherwise languages apply locally but
 * never reach the account, with every other test still green.
 */
describe('appConfig', () => {
  afterEach(() => {
    // Guards the case where the expectations below threw before the surface
    // was ever created, or where a test that never runs this block leaves
    // nothing to remove.
    document.getElementById('boot-error')?.remove();
    jest.restoreAllMocks();
  });

  it('provides the HTTP-backed locale writer, not the no-op default', () => {
    TestBed.configureTestingModule({ providers: appConfig.providers });

    expect(TestBed.inject(LOCALE_WRITER)).toBeInstanceOf(HttpLocaleWriter);
  });

  it('routes a real navigation error through the reporter, not straight to the boot surface', async () => {
    // Functional on purpose: calling reporter.report() directly wouldn't
    // prove withNavigationErrorHandler is wired to it. resetConfig installs a
    // route whose lazy load rejects (a real failed chunk) to raise a real event.
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
  });
});
