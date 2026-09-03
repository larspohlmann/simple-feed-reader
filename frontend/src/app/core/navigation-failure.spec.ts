import { TestBed } from '@angular/core/testing';
import { NavigationFailureReporter } from './navigation-failure';

describe('NavigationFailureReporter', () => {
  let reporter: NavigationFailureReporter;
  let bootSurface: HTMLElement;

  beforeEach(() => {
    // The real static surface from index.html, which the reporter reveals by
    // removing `hidden` — the same element the production document carries.
    bootSurface = document.createElement('div');
    bootSurface.id = 'boot-error';
    bootSurface.hidden = true;
    document.body.appendChild(bootSurface);
    jest.spyOn(console, 'error').mockImplementation(() => undefined);
    reporter = TestBed.inject(NavigationFailureReporter);
  });

  afterEach(() => {
    bootSurface.remove();
    jest.restoreAllMocks();
  });

  it('reveals the static surface when nothing has rendered yet', () => {
    reporter.report(new Error('chunk load failed'));

    expect(bootSurface.hasAttribute('hidden')).toBe(false);
    expect(reporter.failed()).toBe(false);
  });

  it('shows the banner instead once a navigation has succeeded', () => {
    reporter.noteNavigationSucceeded();

    reporter.report(new Error('chunk load failed'));

    expect(reporter.failed()).toBe(true);
    expect(bootSurface.hasAttribute('hidden')).toBe(true);
  });

  it('clears the banner when a later navigation succeeds', () => {
    reporter.noteNavigationSucceeded();
    reporter.report(new Error('chunk load failed'));

    reporter.noteNavigationSucceeded();

    expect(reporter.failed()).toBe(false);
  });
});
