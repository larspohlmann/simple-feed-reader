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
    const ellipsised = ellipsisedAncestor(event.target);
    if (ellipsised === null) return;
    if (ellipsised.hasAttribute('title') && !titled.has(ellipsised)) return;
    if (ellipsised.scrollWidth > ellipsised.clientWidth) {
      ellipsised.setAttribute('title', spokenText(ellipsised));
      titled.add(ellipsised);
      return;
    }
    ellipsised.removeAttribute('title');
    titled.delete(ellipsised);
  };

  inject(NgZone).runOutsideAngular(() => document.addEventListener('mouseover', syncTitle));
  inject(DestroyRef).onDestroy(() => document.removeEventListener('mouseover', syncTitle));
}

function spokenText(element: Element): string {
  const copy = element.cloneNode(true) as Element;
  copy.querySelectorAll('[aria-hidden="true"]').forEach((hidden) => hidden.remove());
  return copy.textContent?.trim() ?? '';
}

function ellipsisedAncestor(target: EventTarget | null): Element | null {
  if (!(target instanceof Element)) return null;
  for (let node: Element | null = target; node !== null; node = node.parentElement) {
    if (getComputedStyle(node).textOverflow === 'ellipsis') return node;
  }
  return null;
}
