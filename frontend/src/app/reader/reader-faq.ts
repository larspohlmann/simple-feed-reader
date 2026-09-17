/**
 * A publisher FAQ ships as native `<details>` disclosures whose default marker
 * is nearly invisible and whose answers start collapsed. This pass tags each
 * one `reader-faq` for the styled treatment and opens it, so every answer shows
 * by default while the row stays collapsible. The narration player is also a
 * `<details>` (`.reader-narration-box`, #903) and must stay a quiet collapsed
 * line, so it is excluded — never widen this selector to plain `details`.
 */
export function expandFaqDisclosures(host: HTMLElement): void {
  for (const disclosure of Array.from(host.querySelectorAll('details'))) {
    if (disclosure.classList.contains('reader-narration-box')) continue;
    disclosure.classList.add('reader-faq');
    disclosure.open = true;
  }
}
