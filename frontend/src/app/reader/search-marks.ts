export interface TextSegment {
  text: string;
  marked: boolean;
}

/** Escapes the characters that would otherwise make a search term act as a
 *  pattern — a user searching "c++" means those plus signs literally. */
function escapeForRegExp(term: string): string {
  return term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

interface CompiledTerms {
  pattern: RegExp;
  lowered: Set<string>;
}

/** The compiled form of the last term list seen, keyed by that list's identity.
 *  Every row of a result page is handed the SAME array (`EntryListComponent`
 *  builds it once), so this one-slot cache hits for every row after the first —
 *  without it a 50-row page recompiles the same pattern 100 times. */
let lastTerms: string[] | null = null;
let lastCompiled: CompiledTerms | null = null;

function compile(usable: string[], terms: string[]): CompiledTerms {
  if (terms === lastTerms && lastCompiled !== null) return lastCompiled;

  // split() on a capturing group returns matches interleaved with the gaps, and
  // every match is one of the terms -- so a set lookup decides a piece without a
  // second regex, and without the lastIndex state a reused /g pattern would carry.
  const compiled: CompiledTerms = {
    pattern: new RegExp(`(${usable.map(escapeForRegExp).join('|')})`, 'gi'),
    lowered: new Set(usable.map((term) => term.toLowerCase())),
  };
  lastTerms = terms;
  lastCompiled = compiled;

  return compiled;
}

/**
 * Cuts `text` into marked and unmarked pieces. The caller renders the pieces as
 * elements, so nothing here is ever interpolated as HTML — a title containing
 * markup stays text.
 */
export function markTerms(text: string, terms: string[]): TextSegment[] {
  const usable = terms.filter((t) => t.length > 0);
  if (!text || usable.length === 0) return [{ text, marked: false }];

  const { pattern, lowered } = compile(usable, terms);

  return text
    .split(pattern)
    .filter((piece) => piece.length > 0)
    .map((piece) => ({ text: piece, marked: lowered.has(piece.toLowerCase()) }));
}
