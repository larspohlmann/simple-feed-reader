import { DOCUMENT } from '@angular/common';
import { DestroyRef, EffectRef, Signal, effect, inject } from '@angular/core';
import { pageScrollingElement } from '../../core/page-scrolling-element';

/** On `<html>` while the reader owns the page; `styles/_base.scss` keys the page rules to it. */
export const READER_PAGE_CLASS = 'reader-page';
/** On `<html>` while `locked` holds the page still. Not CDK's block strategy: its fixed `<html>`
 *  shrinks the page to the viewport, which expands Brave's collapsed toolbar (#2088). */
export const PAGE_HELD_CLASS = 'page-held';

export function holdReaderPage(locked: Signal<boolean>): EffectRef {
  const document = inject(DOCUMENT);
  const root = document.documentElement;
  const history = document.defaultView?.history;
  const restoration = history?.scrollRestoration;
  root.classList.add(READER_PAGE_CLASS);
  if (history) history.scrollRestoration = 'manual';
  inject(DestroyRef).onDestroy(() => {
    root.classList.remove(READER_PAGE_CLASS, PAGE_HELD_CLASS);
    if (history && restoration) history.scrollRestoration = restoration;
    pageScrollingElement(document).scrollTop = 0;
  });
  return effect(() => root.classList.toggle(PAGE_HELD_CLASS, locked()));
}
