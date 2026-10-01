import { Directive, ElementRef, inject, input } from '@angular/core';
import { listenToScrollOutsideZone } from './scroll-outside-zone';

/** Hands the host's scroll events to a handler outside the Angular zone. */
@Directive({
  selector: '[appScrollOutsideZone]',
})
export class ScrollOutsideZoneDirective {
  readonly handler = input.required<(event: Event) => void>({ alias: 'appScrollOutsideZone' });

  constructor() {
    listenToScrollOutsideZone(inject<ElementRef<HTMLElement>>(ElementRef).nativeElement, (event) =>
      this.handler()(event),
    );
  }
}
