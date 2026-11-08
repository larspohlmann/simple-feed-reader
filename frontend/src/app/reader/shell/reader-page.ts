import { DOCUMENT } from '@angular/common';
import { DestroyRef, EffectRef, Signal, effect, inject } from '@angular/core';
import { pageScrollingElement } from '../../core/page-scrolling-element';
import { PageHold } from '../../shared/page-hold/page-hold';

/** On `<html>` while the reader owns the page; `styles/_base.scss` keys the page rules to it. */
export const READER_PAGE_CLASS = 'reader-page';

export function holdReaderPage(locked: Signal<boolean>): EffectRef {
  const document = inject(DOCUMENT);
  const pageHold = inject(PageHold);
  const root = document.documentElement;
  const history = document.defaultView?.history;
  const restoration = history?.scrollRestoration;
  root.classList.add(READER_PAGE_CLASS);
  if (history) history.scrollRestoration = 'manual';
  inject(DestroyRef).onDestroy(() => {
    root.classList.remove(READER_PAGE_CLASS);
    if (history && restoration) history.scrollRestoration = restoration;
    pageScrollingElement(document).scrollTop = 0;
  });
  return effect((onCleanup) => {
    if (locked()) onCleanup(pageHold.hold());
  });
}
