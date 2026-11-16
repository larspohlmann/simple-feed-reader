import type { NgZone } from '@angular/core';
import { observeResize } from '../../shared/observe-resize';

/** Publishes the header's height as `--list-bar-h` on the host while `isPublishing()`; returns its removal. */
export function publishListBarHeight(
  zone: Pick<NgZone, 'runOutsideAngular'>,
  host: HTMLElement,
  header: HTMLElement,
  isPublishing: () => boolean = () => true,
): () => void {
  return observeResize(zone, [header], () => {
    if (isPublishing() && header.offsetHeight > 0)
      host.style.setProperty('--list-bar-h', `${header.offsetHeight}px`);
  });
}
