/** The closest ancestor that scrolls its own content, or null when only the document does.
 *  An IntersectionObserver's `rootMargin` stretches its root alone, so a target inside a
 *  nested scroller never nears a viewport root — it has to observe against that scroller. */
export function nearestScroller(element: Element): Element | null {
  for (let node = element.parentElement; node; node = node.parentElement) {
    if (/auto|scroll/.test(getComputedStyle(node).overflowY)) return node;
  }
  return null;
}
