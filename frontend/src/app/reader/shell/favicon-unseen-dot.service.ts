import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, computed, effect, inject } from '@angular/core';
import { SubscriptionsStore } from '../state/subscriptions.store';

const BADGED_VECTOR_HREF = 'favicon-unseen.svg?v=1';
const BADGED_RASTER_HREF = 'favicon-unseen-32.png?v=1';

/** Injected for its effect. */
@Injectable()
export class FaviconUnseenDot {
  private readonly icons = Array.from(
    inject(DOCUMENT).head.querySelectorAll<HTMLLinkElement>('link[rel="icon"]'),
    (link) => ({
      link,
      plainHref: link.getAttribute('href') ?? '',
      badgedHref:
        link.getAttribute('type') === 'image/svg+xml' ? BADGED_VECTOR_HREF : BADGED_RASTER_HREF,
    }),
  );

  constructor() {
    const unseenTotal = inject(SubscriptionsStore).unseenNotificationsTotal;
    const anyUnseen = computed(() => unseenTotal() > 0);
    effect(() => (anyUnseen() ? this.showBadged() : this.showPlain()));
    inject(DestroyRef).onDestroy(() => this.showPlain());
  }

  private showBadged(): void {
    for (const { link, badgedHref } of this.icons) link.setAttribute('href', badgedHref);
  }

  private showPlain(): void {
    for (const { link, plainHref } of this.icons) link.setAttribute('href', plainHref);
  }
}
