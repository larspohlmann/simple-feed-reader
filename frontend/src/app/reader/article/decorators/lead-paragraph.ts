/**
 * Tags the article's first real paragraph with class `lead` for stylesheet weight.
 * Runs post-render because wrapper elements from feeds/readability put the lead
 * out of reach of a plain CSS sibling selector. Idempotent: stale tags are cleared first.
 */
export function markLeadParagraph(host: HTMLElement): void {
  for (const paragraph of Array.from(host.querySelectorAll('p'))) {
    paragraph.classList.remove('lead');
  }
  for (const paragraph of Array.from(host.querySelectorAll('p'))) {
    if ((paragraph.textContent ?? '').trim() !== '') {
      paragraph.classList.add('lead');
      return;
    }
  }
}
