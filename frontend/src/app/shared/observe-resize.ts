import type { NgZone } from '@angular/core';

/** Calls back when any target changes size, outside the Angular zone (#501); returns its removal. */
export function observeResize(
  zone: Pick<NgZone, 'runOutsideAngular'>,
  targets: readonly Element[],
  callback: () => void,
): () => void {
  if (typeof ResizeObserver === 'undefined') return () => undefined;
  const observer = zone.runOutsideAngular(() => new ResizeObserver(() => callback()));
  for (const target of targets) observer.observe(target);
  return () => observer.disconnect();
}
