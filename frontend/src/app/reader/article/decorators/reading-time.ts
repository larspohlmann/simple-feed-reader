/** An ordinary adult reading pace; the estimate is coarse by nature. */
const READING_WORDS_PER_MINUTE = 220;

/**
 * Estimated minutes to read an HTML fragment, or null when it rounds below a
 * minute (a bare link, an empty summary) so the meta line can skip it. Tags
 * are dropped textually — parsing the fragment into a DOM (even detached)
 * would start image fetches, and an estimate does not need DOM fidelity.
 */
export function estimateReadingMinutes(html: string): number | null {
  const text = html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&[a-z#0-9]+;/gi, ' ')
    .trim();
  if (text === '') return null;
  const words = text.split(/\s+/).length;
  const minutes = Math.round(words / READING_WORDS_PER_MINUTE);
  return minutes < 1 ? null : minutes;
}
