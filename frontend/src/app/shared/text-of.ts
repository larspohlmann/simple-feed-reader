/** The text a piece of HTML reads as, without its markup. */
export function textOf(html: string | null): string {
  if (!html) return '';
  return (new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '').trim();
}
