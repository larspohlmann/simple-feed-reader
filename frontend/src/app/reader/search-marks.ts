// src/app/reader/search-marks.ts

export interface TextSegment {
  text: string;
  marked: boolean;
}

/** Escapes the characters that would otherwise make a search term act as a
 *  pattern — a user searching "c++" means those plus signs literally. */
function escapeForRegExp(term: string): string {
  return term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Cuts `text` into marked and unmarked pieces. The caller renders the pieces as
 * elements, so nothing here is ever interpolated as HTML — a title containing
 * markup stays text.
 */
export function markTerms(text: string, terms: string[]): TextSegment[] {
  const usable = terms.filter((t) => t.length > 0);
  if (!text || usable.length === 0) return [{ text, marked: false }];

  // split() on a capturing group returns the matches interleaved with the gaps,
  // and every match is one of the terms — so a set lookup decides a piece
  // without a second regular expression, and without the lastIndex state a
  // reused /g pattern would carry between calls.
  const pattern = new RegExp(`(${usable.map(escapeForRegExp).join('|')})`, 'gi');
  const lowered = new Set(usable.map((term) => term.toLowerCase()));

  return text
    .split(pattern)
    .filter((piece) => piece.length > 0)
    .map((piece) => ({ text: piece, marked: lowered.has(piece.toLowerCase()) }));
}
