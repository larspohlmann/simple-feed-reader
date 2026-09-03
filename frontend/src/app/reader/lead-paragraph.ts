/**
 * Tags the article's first real paragraph with class `lead` for stylesheet weight.
 * Runs post-render because wrapper elements from feeds/readability put the lead
 * out of reach of a plain CSS sibling selector. Idempotent: stale tags are cleared first.
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
