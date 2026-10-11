import { DestroyRef, NgZone, inject } from '@angular/core';

export function isTabHidden(): boolean {
  return document.visibilityState === 'hidden';
}

/** Calls `onChange` outside the Angular zone each time the tab is hidden or shown, until the
 *  calling injector is destroyed. Call it in an injection context. */
export function onTabVisibilityChange(onChange: (hidden: boolean) => void): void {
  const listener = (): void => onChange(isTabHidden());
  inject(NgZone).runOutsideAngular(() => document.addEventListener('visibilitychange', listener));
  inject(DestroyRef).onDestroy(() => document.removeEventListener('visibilitychange', listener));
}
