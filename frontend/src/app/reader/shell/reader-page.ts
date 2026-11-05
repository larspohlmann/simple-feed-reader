import { DOCUMENT } from '@angular/common';
import { DestroyRef, EffectRef, Injector, NgZone, Signal, effect, inject } from '@angular/core';
import { BlockScrollStrategy, createBlockScrollStrategy } from '@angular/cdk/overlay';

/** On `<html>` while the reader owns the page; `styles/_base.scss` keys the page rules to it. */
export const READER_PAGE_CLASS = 'reader-page';

/** The reader scrolls the document (#2038): it claims the page while mounted, pins it while
 *  `locked` holds, and leaves it at the top for the next route. `content` is what grows with
 *  the list, since `<html>` and `<body>` hold at the viewport height. Call in an injection context. */
export function holdReaderPage(content: HTMLElement, locked: Signal<boolean>): EffectRef {
  const document = inject(DOCUMENT);
  const zone = inject(NgZone);
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
  return effect((onCleanup) => {
    if (!locked()) {
      block.disable();
      return;
    }
    block.enable();
    onCleanup(zone.runOutsideAngular(() => retryPin(block, root, content)));
  });
}

/** CDK's `enable()` no-ops while the page fits the viewport or a dialog holds the block. */
function retryPin(block: BlockScrollStrategy, root: HTMLElement, content: HTMLElement): () => void {
  const retry = (): void => block.enable();
  const released = new MutationObserver(retry);
  released.observe(root, { attributes: true, attributeFilter: ['class'] });
  const grown = typeof ResizeObserver === 'undefined' ? undefined : new ResizeObserver(retry);
  grown?.observe(content);
  return () => {
    released.disconnect();
    grown?.disconnect();
  };
}
