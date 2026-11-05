import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, inject } from '@angular/core';
import { ScrollHosts } from '../../shared/scroll-host/scroll-hosts.service';
import type { PageLayer } from '../../shared/scroll-host/page-layer';

/** On `<html>` while a layer over the list owns the page; its rail stands in for the scrollbar. */
export const PAGE_OVERLAID_CLASS = 'page-overlaid';

/** One page, stacked layers (#2038): the top one is in the flow and owns the document's offset,
 *  every one beneath is pinned at its own. The one place a layer switch writes the page offset. */
@Injectable()
export class PageScrollOwner {
  private readonly document = inject(DOCUMENT);
  private readonly page = inject(ScrollHosts).forPage(this.document.body);
  private readonly layers: PageLayer[] = [];

  constructor() {
    // Listening arms the host's release observer: a write under a CDK block lands after it.
    const stopListening = this.page.listen(() => undefined);
    inject(DestroyRef).onDestroy(() => {
      stopListening();
      this.document.documentElement.classList.remove(PAGE_OVERLAID_CLASS);
    });
  }

  enter(layer: PageLayer): void {
    const beneath = this.layers.at(-1);
    this.layers.push(layer);
    this.markOverlaid();
    if (!beneath) return;
    beneath.pinAt(this.page.scrollTop);
    this.page.scrollTop = 0;
  }

  /** Pins the leaver so its leave animation plays in place, and gives the page back to the
   *  layer beneath at its offset, all before the next paint (spec Risk 2). */
  leave(layer: PageLayer): void {
    const index = this.layers.indexOf(layer);
    if (index === -1) return;
    const onTop = index === this.layers.length - 1;
    this.layers.splice(index, 1);
    this.markOverlaid();
    if (!onTop) return;
    layer.pinAt(this.page.scrollTop);
    const beneath = this.layers.at(-1);
    if (beneath) this.page.scrollTop = beneath.unpin();
  }

  private markOverlaid(): void {
    this.document.documentElement.classList.toggle(PAGE_OVERLAID_CLASS, this.layers.length > 1);
  }
}
