import { DestroyRef, Directive, ElementRef, NgZone, inject, input } from '@angular/core';

/**
 * A `scroll` listener outside the Angular zone. A template `(scroll)` ends every
 * scroll event in a tree-wide change-detection tick; on a long list that tick
 * misses the frame and iOS WebKit shows unpainted tiles as a blink (#501).
 */
@Directive({
  selector: '[appScrollOutsideZone]',
})
export class ScrollOutsideZoneDirective {
  readonly handler = input.required<(event: Event) => void>({ alias: 'appScrollOutsideZone' });

  constructor() {
    const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    const listener = (event: Event): void => this.handler()(event);
    inject(NgZone).runOutsideAngular(() =>
      host.addEventListener('scroll', listener, { passive: true }),
    );
    inject(DestroyRef).onDestroy(() => host.removeEventListener('scroll', listener));
  }
}
