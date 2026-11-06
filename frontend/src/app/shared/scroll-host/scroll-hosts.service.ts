import { DOCUMENT } from '@angular/common';
import { Injectable, NgZone, inject } from '@angular/core';
import { ElementScrollHost, ScrollHost } from './scroll-host';
import { PageScrollHost } from './page-scroll-host';
import type { PageLayer } from './page-layer';

@Injectable({ providedIn: 'root' })
export class ScrollHosts {
  private readonly zone = inject(NgZone);
  private readonly document = inject(DOCUMENT);
  private readonly outsideZone = <T>(run: () => T): T => this.zone.runOutsideAngular(run);
  private readonly pageWriteListeners = new Set<(top: number) => void>();
  private readonly announcePageWrite = (top: number): void =>
    this.pageWriteListeners.forEach((listener) => listener(top));

  forElement(element: HTMLElement): ScrollHost {
    return new ElementScrollHost(element, this.outsideZone);
  }

  forPage(content: HTMLElement, layer: PageLayer | null = null): ScrollHost {
    return new PageScrollHost(
      content,
      {
        view: this.document.defaultView ?? window,
        outsideZone: this.outsideZone,
        announceWrite: this.announcePageWrite,
      },
      layer,
    );
  }

  /** Hears every offset the app writes to the page, through any page host; returns its removal. */
  observePageWrites(listener: (top: number) => void): () => void {
    this.pageWriteListeners.add(listener);
    return () => this.pageWriteListeners.delete(listener);
  }
}
