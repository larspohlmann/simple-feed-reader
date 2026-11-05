import { DOCUMENT } from '@angular/common';
import { DestroyRef, EffectRef, Injector, Signal, effect, inject } from '@angular/core';
import { createBlockScrollStrategy } from '@angular/cdk/overlay';

/** On `<html>` while the reader owns the page; `styles/_base.scss` keys the page rules to it. */
export const READER_PAGE_CLASS = 'reader-page';

/** The reader scrolls the document (#2038): it claims the page while mounted, pins it while
 *  `locked` holds, and leaves it at the top for the next route. Call in an injection context. */
export function holdReaderPage(locked: Signal<boolean>): EffectRef {
  const document = inject(DOCUMENT);
  const root = document.documentElement;
  const history = document.defaultView?.history;
  const restoration = history?.scrollRestoration;
  const block = createBlockScrollStrategy(inject(Injector));
  root.classList.add(READER_PAGE_CLASS);
  if (history) history.scrollRestoration = 'manual';
  inject(DestroyRef).onDestroy(() => {
    block.disable();
    root.classList.remove(READER_PAGE_CLASS);
    if (history && restoration) history.scrollRestoration = restoration;
    (document.scrollingElement ?? root).scrollTop = 0;
  });
  return effect(() => {
    if (locked()) block.enable();
    else block.disable();
  });
}
