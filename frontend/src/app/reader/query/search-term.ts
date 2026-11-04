/** The shortest term the backend will accept. A shorter one is not an error
 *  here — it is a half-typed word, so the URL simply carries no search yet. */
export const MIN_SEARCH_LENGTH = 3;

/** Whether a search term ends in whitespace, which the backend
 *  (`SearchTermsModel::fromInput`) reads as "match whole words only" rather than
 *  substrings. Exported rather than inlined: this exact question already had
 *  three drifted answers on this branch (#408); `normalizeSearchInput` and the
 *  whole-word badge both call this one function instead.
 *
 *  The class is `[\s\p{Z}]`, matching the backend's `SearchTermsModel::WHITESPACE`
 *  exactly. PHP's `\s` under `/u` is ASCII-only, so the backend adds `\p{Z}`
 *  for Unicode separators (e.g. NBSP); JS's `\s` covers `\p{Z}` already but
 *  also ASCII control whitespace (tab, newline, etc.) that `\p{Z}` alone
 *  misses. `\p{Z}` alone here (round 1's mistake) silently dropped a pasted
 *  trailing tab/newline; the union is the one set both languages agree on. */
export function isWholeWordTerm(term: string): boolean {
  return /[\s\p{Z}]$/u.test(term);
}

/** Whether a search term (`SearchTermsModel::fromInput`) is wrapped in double quotes,
 *  read as "match this exact phrase" rather than each word anywhere. A phrase
 *  overrides whole-word mode when both signals are present, so any "is this
 *  whole-word?" check must set a phrase aside first. */
export function isPhraseTerm(term: string): boolean {
  return phraseWithin(term) !== null;
}

/** The exact phrase inside a quoted query, or null when not a phrase (including
 *  a quoted-but-blank one). Mirrors the server's `SearchTermsModel::phraseWithin`:
 *  trimmed input opens/closes with a quote, inner quotes become boundaries,
 *  inner whitespace collapses to one space. */
export function phraseWithin(term: string): string | null {
  const trimmed = term.trim();
  if (trimmed.length < 2 || !trimmed.startsWith('"') || !trimmed.endsWith('"')) return null;
  const inner = trimmed
    .slice(1, -1)
    .replace(/"/g, ' ')
    .replace(/[\s\p{Z}]+/gu, ' ')
    .trim();
  return inner.length > 0 ? inner : null;
}

/** Strips leading whitespace and collapses inner runs to one space, but —
 *  unlike `String.trim()` — keeps one trailing space when the raw input ended
 *  in whitespace: the server reads that as "whole words only" (#408 follow-up),
 *  and a plain `trim()` would destroy the signal. Shared by the field's
 *  settled-emission path and `selectionFromParams` so both agree on "the term". */
export function normalizeSearchInput(raw: string): string {
  const hasTrailingSpace = isWholeWordTerm(raw);
  const collapsed = raw.trim().replace(/\s+/g, ' ');
  if (collapsed.length === 0) return '';
  return hasTrailingSpace ? `${collapsed} ` : collapsed;
}

/** Whether a term is long enough to search on. Measured on the TRIMMED value:
 *  'ab ' is three raw characters but two real ones, since the trailing space is
 *  the whole-word-match signal, not a character of the word (`isWholeWordTerm`).
 *
 *  Exported for the same reason `isWholeWordTerm` is: this rule had three
 *  writers with their own copy of the comment before, and drifted (#408). */
export function isSearchableTerm(term: string): boolean {
  return term.trim().length >= MIN_SEARCH_LENGTH;
}

/** A term the user has begun but not finished: something is typed, but not yet
 *  enough to search on. The empty box is NOT too short — it means "no search",
 *  which is always a valid state. */
export function isTooShortToSearch(term: string): boolean {
  return term.trim().length > 0 && !isSearchableTerm(term);
}

/** The individual words of a term, for marking them in result rows. Splits on
 *  the same whitespace class as the rest of the vocabulary and drops the empty
 *  piece a trailing space leaves. Lives beside `normalizeSearchInput` et al. */
export function searchWords(term: string): string[] {
  // A phrase is marked as one contiguous run, not word by word — the same
  // string the server matched — so a quoted `"climate change"` highlights only
  // where the two words sit together, never each on its own.
  const phrase = phraseWithin(term);
  if (phrase !== null) return [phrase];
  return term.split(/[\s\p{Z}]+/u).filter((word) => word.length > 0);
}
