import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, effect, inject } from '@angular/core';
import { SubscriptionsStore } from '../state/subscriptions.store';

const BADGED_VECTOR_HREF = 'favicon-unseen.svg?v=1';
const BADGED_RASTER_HREF = 'favicon-unseen-32.png?v=1';

/** Puts a dot on the tab's favicon while a notification is unseen (#2515).
 *  Injected for its effect. */
@Injectable()
export class FaviconUnseenDot {
  private readonly icons = Array.from(
    inject(DOCUMENT).head.querySelectorAll<HTMLLinkElement>('link[rel="icon"]'),
    (link) => ({ link, plainHref: link.getAttribute('href') ?? '' }),
  );

  constructor() {
    const unseenTotal = inject(SubscriptionsStore).unseenNotificationsTotal;
    effect(() => (unseenTotal() > 0 ? this.showBadged() : this.showPlain()));
    inject(DestroyRef).onDestroy(() => this.showPlain());
  }

  private showBadged(): void {
    for (const { link } of this.icons) {
      const isVector = link.getAttribute('type') === 'image/svg+xml';
      link.setAttribute('href', isVector ? BADGED_VECTOR_HREF : BADGED_RASTER_HREF);
    }
  }

  private showPlain(): void {
    for (const { link, plainHref } of this.icons) link.setAttribute('href', plainHref);
  }
}
