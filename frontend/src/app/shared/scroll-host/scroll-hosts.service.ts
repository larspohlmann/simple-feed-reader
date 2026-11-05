import { Injectable, NgZone, inject } from '@angular/core';
import { ElementScrollHost, ScrollHost } from './scroll-host';

@Injectable({ providedIn: 'root' })
export class ScrollHosts {
  private readonly zone = inject(NgZone);

  forElement(element: HTMLElement): ScrollHost {
    return new ElementScrollHost(element, (run) => this.zone.runOutsideAngular(run));
  }
}
