import { DOCUMENT } from '@angular/common';
import { Injectable, NgZone, inject } from '@angular/core';
import { ElementScrollHost, ScrollHost } from './scroll-host';
import { PageScrollHost } from './page-scroll-host';

@Injectable({ providedIn: 'root' })
export class ScrollHosts {
  private readonly zone = inject(NgZone);
  private readonly document = inject(DOCUMENT);
  private readonly outsideZone = <T>(run: () => T): T => this.zone.runOutsideAngular(run);

  forElement(element: HTMLElement): ScrollHost {
    return new ElementScrollHost(element, this.outsideZone);
  }

  forPage(content: HTMLElement): ScrollHost {
    return new PageScrollHost(content, this.document.defaultView ?? window, this.outsideZone);
  }
}
