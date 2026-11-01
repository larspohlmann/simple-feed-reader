import { InjectionToken } from '@angular/core';

/** Loads the page afresh, so the browser fetches the current index.html and its bundles. */
export const PAGE_RELOAD = new InjectionToken<() => void>('PAGE_RELOAD', {
  providedIn: 'root',
  factory: () => () => location.reload(),
});
