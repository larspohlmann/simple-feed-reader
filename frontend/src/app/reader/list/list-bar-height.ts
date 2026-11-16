import type { NgZone } from '@angular/core';
import { observeResize } from '../../shared/observe-resize';

export interface ListBarHeight {
  readonly host: HTMLElement;
  readonly header: HTMLElement;
  readonly isPublishing?: () => boolean;
}

/** Publishes the header's height as `--list-bar-h` on the host while `isPublishing()`; returns its removal. */
export function publishListBarHeight(
  zone: Pick<NgZone, 'runOutsideAngular'>,
  { host, header, isPublishing = () => true }: ListBarHeight,
): () => void {
  return observeResize(zone, [header], () => {
    if (isPublishing() && header.offsetHeight > 0)
      host.style.setProperty('--list-bar-h', `${header.offsetHeight}px`);
  });
}
