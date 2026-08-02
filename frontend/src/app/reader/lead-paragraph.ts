/**
 * Tags the article's first real paragraph with class `lead`, so the
 * stylesheet can give it a little more weight. Runs in the component's
 * post-render pass because wrapper elements from feeds and readability sit
 * between the container and the paragraphs, which puts the lead out of reach
 * of a plain CSS sibling selector. Idempotent across re-renders: stale tags
 * are cleared first.
 */
export function markLeadParagraph(host: HTMLElement): void {
  for (const p of Array.from(host.querySelectorAll('p'))) {
    p.classList.remove('lead');
  }
  for (const p of Array.from(host.querySelectorAll('p'))) {
    if ((p.textContent ?? '').trim() !== '') {
      p.classList.add('lead');
      return;
    }
  }
}
