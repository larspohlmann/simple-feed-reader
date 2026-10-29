/**
 * A publisher FAQ ships as native `<details>` disclosures whose default marker
 * is nearly invisible and whose answers start collapsed. This pass tags each
 * one `reader-faq` for the styled treatment and opens it, so every answer shows
 * by default while the row stays collapsible.
 */
export function expandFaqDisclosures(host: HTMLElement): void {
  for (const disclosure of Array.from(host.querySelectorAll('details'))) {
    disclosure.classList.add('reader-faq');
    disclosure.open = true;
  }
}
