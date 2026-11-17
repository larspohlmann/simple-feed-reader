import { DOCUMENT, DestroyRef, NgZone, inject } from '@angular/core';

/**
 * Gives every `text-overflow: ellipsis` text that is cut off its full text as a native tooltip,
 * measured on hover. A title the template set itself always wins; only the titles set here are
 * ever removed again.
 */
export function startTruncatedTextTitles(): void {
  const document = inject(DOCUMENT);
  const titled = new WeakSet<Element>();

  const syncTitle = (event: Event): void => {
    const ellipsised = ellipsisedAncestor(event.target, document);
    if (ellipsised === null) return;
    if (ellipsised.hasAttribute('title') && !titled.has(ellipsised)) return;
    if (ellipsised.scrollWidth > ellipsised.clientWidth) {
      ellipsised.setAttribute('title', ellipsised.textContent?.trim() ?? '');
      titled.add(ellipsised);
      return;
    }
    ellipsised.removeAttribute('title');
    titled.delete(ellipsised);
  };

  inject(NgZone).runOutsideAngular(() => document.addEventListener('mouseover', syncTitle));
  inject(DestroyRef).onDestroy(() => document.removeEventListener('mouseover', syncTitle));
}

function ellipsisedAncestor(target: EventTarget | null, document: Document): Element | null {
  const view = document.defaultView;
  if (view === null || !(target instanceof Element)) return null;
  for (let node: Element | null = target; node !== null; node = node.parentElement) {
    if (view.getComputedStyle(node).textOverflow === 'ellipsis') return node;
  }
  return null;
}
