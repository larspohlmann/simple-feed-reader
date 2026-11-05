import { DOCUMENT } from '@angular/common';
import { DestroyRef, EffectRef, Injector, NgZone, Signal, effect, inject } from '@angular/core';
import { BlockScrollStrategy, createBlockScrollStrategy } from '@angular/cdk/overlay';
import { observeResize } from '../../shared/observe-resize';
import { pageScrollingElement } from '../../core/page-scrolling-element';

/** On `<html>` while the reader owns the page; `styles/_base.scss` keys the page rules to it. */
export const READER_PAGE_CLASS = 'reader-page';

/** `content` is what grows with the list: `<html>` and `<body>` hold at the viewport height. */
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
    pageScrollingElement(document).scrollTop = 0;
  });
  return effect((onCleanup) => {
    if (!locked()) {
      block.disable();
      return;
    }
    block.enable();
    onCleanup(retryPin(zone, block, content));
  });
}

/** CDK's `enable()` no-ops while the page fits the viewport or a dialog holds the block. */
function retryPin(zone: NgZone, block: BlockScrollStrategy, content: HTMLElement): () => void {
  const retry = (): void => block.enable();
  const released = zone.runOutsideAngular(() => new MutationObserver(retry));
  released.observe(content.ownerDocument.documentElement, {
    attributes: true,
    attributeFilter: ['class'],
  });
  const stopGrowing = observeResize(zone, [content], retry);
  return () => {
    released.disconnect();
    stopGrowing();
  };
}
