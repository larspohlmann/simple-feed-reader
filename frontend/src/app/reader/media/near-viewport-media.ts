import { DestroyRef, Injectable, inject } from '@angular/core';

const NEAR_VIEWPORT = '100% 0px';

export interface NearViewportClient {
  approach?(): void;
  leave(): void;
}

@Injectable()
export class NearViewportMedia {
  private readonly clients = new Map<Element, NearViewportClient>();
  private observer: IntersectionObserver | null = null;

  constructor() {
    inject(DestroyRef).onDestroy(() => this.observer?.disconnect());
  }

  /** The list scrolls inside its own box, so only a margin rooted on it reaches beyond the screen. */
  scrollWithin(root: Element): void {
    if (typeof IntersectionObserver === 'undefined') return;
    this.observer?.disconnect();
    this.observer = new IntersectionObserver((changes) => this.sight(changes), {
      root,
      rootMargin: NEAR_VIEWPORT,
    });
    for (const element of this.clients.keys()) this.observer.observe(element);
  }

  watch(element: Element, client: NearViewportClient): void {
    this.clients.set(element, client);
    if (typeof IntersectionObserver === 'undefined') client.approach?.();
    else this.observer?.observe(element);
  }

  unwatch(element: Element): void {
    this.clients.delete(element);
    this.observer?.unobserve(element);
  }

  private sight(changes: IntersectionObserverEntry[]): void {
    for (const change of changes) {
      const client = this.clients.get(change.target);
      if (change.isIntersecting) client?.approach?.();
      else client?.leave();
    }
  }
}

export function keepNear(
  nearViewport: NearViewportMedia | null,
  element: Element,
  client: NearViewportClient,
): void {
  if (nearViewport === null) client.approach?.();
  else nearViewport.watch(element, client);
}
