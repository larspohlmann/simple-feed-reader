import { DestroyRef, NgZone, inject } from '@angular/core';

/**
 * A `scroll` listener outside the Angular zone, removed with the calling view. A
 * template `(scroll)` ends every scroll event in a tree-wide change-detection tick;
 * on a long list that tick misses the frame and iOS WebKit shows unpainted tiles as
 * a blink (#501). Call it in an injection context.
 */
export function listenToScrollOutsideZone(
  element: HTMLElement,
  listener: (event: Event) => void,
): void {
  inject(NgZone).runOutsideAngular(() =>
    element.addEventListener('scroll', listener, { passive: true }),
  );
  inject(DestroyRef).onDestroy(() => element.removeEventListener('scroll', listener));
}
