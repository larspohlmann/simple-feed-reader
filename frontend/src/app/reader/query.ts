// src/app/reader/query.ts
import { ParamMap, Params, convertToParamMap } from '@angular/router';
import { EntryQuery, MarkReadScope } from './models';
import { entryIdFromParam } from './slug';

export interface Selection {
  kind: 'all' | 'tag' | 'subscription' | 'favorites' | 'kept' | 'viewed' | 'for-you' | 'search';
  id: number | null;
  unread: boolean;
  /** Only a search carries one. Part of the list's identity, so it belongs to
   *  the selection rather than to a service beside it. */
  term?: string;
}

/** The shortest term the backend will accept. A shorter one is not an error
 *  here — it is a half-typed word, so the URL simply carries no search yet. */
export const MIN_SEARCH_LENGTH = 3;

/** Whether a search term ends in whitespace, which the backend
 *  (`SearchTerms::fromInput`) reads as "match whole words only" rather than
 *  substrings. Exported — not inlined at each call site — because this
 *  exact question already has three answers on this branch that disagreed
 *  (a comparator, a parameter vocabulary, a header-visibility rule, each
 *  duplicated and drifted, #408). `normalizeSearchInput` below and the
 *  whole-word badge in `entry-list.component.ts` both call this one
 *  function so a fourth divergence can't happen.
 *
 *  The class is `[\s\p{Z}]`, matching the backend's `SearchTerms::WHITESPACE`
 *  exactly — neither half is redundant. PHP's `\s` under `/u` is ASCII-only,
 *  so the backend adds `\p{Z}` to also catch a Unicode separator (e.g.
 *  NBSP). JavaScript's `\s` already covers every `\p{Z}` code point, but it
 *  additionally matches ASCII control whitespace — tab, newline, vertical
 *  tab, form feed, carriage return — that `\p{Z}` alone does not. Using only
 *  `\p{Z}` here (round 1's mistake) silently dropped those characters: a
 *  term pasted or autocompleted with a trailing tab or newline stopped
 *  triggering whole-word mode even though the backend still applies it. The
 *  union is the one set both languages agree on. */
export function isWholeWordTerm(term: string): boolean {
  return /[\s\p{Z}]$/u.test(term);
}

/** Strips leading whitespace and collapses inner runs to a single space, but
 *  — unlike `String.trim()` — keeps exactly one trailing space when the raw
 *  input ended in whitespace. The server reads a trailing space as "match
 *  whole words only" versus substring matching, one mode for the whole query
 *  (#408 follow-up); a plain `trim()` here would destroy that signal before
 *  it ever reaches the request. Shared by the field's settled-emission path
 *  and by `selectionFromParams`, the two places a raw `q` value is turned
 *  into a term — both must agree on what "the term" is. */
export function normalizeSearchInput(raw: string): string {
  const hasTrailingSpace = isWholeWordTerm(raw);
  const collapsed = raw.trim().replace(/\s+/g, ' ');
  if (collapsed.length === 0) return '';
  return hasTrailingSpace ? `${collapsed} ` : collapsed;
}

/** Whether a term is long enough to search on. Measured on the TRIMMED value,
 *  which is the whole subtlety: 'ab ' is three raw characters but two real
 *  ones, because the trailing space is the whole-word-match signal rather
 *  than a character of the word (see `isWholeWordTerm`).
 *
 *  Exported for the same reason `isWholeWordTerm` is: the rule had three
 *  writers — `selectionFromParams` deciding whether the URL names a search,
 *  and the field deciding both when to show its too-short hint and when to
 *  emit — each with its own phrasing and its own copy of this comment. That
 *  is precisely how the comparator, the parameter vocabulary and the header
 *  rule each drifted on this branch (#408). */
export function isSearchableTerm(term: string): boolean {
  return term.trim().length >= MIN_SEARCH_LENGTH;
}

/** A term the user has begun but not finished: something is typed, but not yet
 *  enough to search on. The empty box is NOT too short — it means "no search",
 *  which is always a valid state. */
export function isTooShortToSearch(term: string): boolean {
  return term.trim().length > 0 && !isSearchableTerm(term);
}

/** The individual words of a term, for marking them in the result rows. Splits
 *  on the same whitespace class the rest of the vocabulary uses, and drops the
 *  empty piece a trailing space would otherwise leave behind. Lives here beside
 *  `normalizeSearchInput`, `visibleSearchTerm` and `isWholeWordTerm` so that
 *  every answer to "what is a term made of" has one home. */
export function searchWords(term: string): string[] {
  return term.split(/[\s\p{Z}]+/u).filter((word) => word.length > 0);
}

/** How a scoped refresh is keyed. Empty = all the user's due feeds; feedId =
 *  one feed; tagId = every feed carrying that tag. */
export interface RefreshScope {
  feedId?: number;
  tagId?: number;
}

/** Whether two selections name the same list. Selections are rebuilt from the
 *  route on every navigation, so they are never reference-equal. */
export function sameSelection(a: Selection, b: Selection): boolean {
  return a.kind === b.kind && a.id === b.id && a.unread === b.unread && a.term === b.term;
}

/** `Selection.term` with the trailing space — the whole-word-match signal —
 *  stripped for display. The space must reach the server (`EntryQuery.q`)
 *  and must stay in `Selection.term` (it is part of the selection's
 *  identity, see `sameSelection`); this is only for the strings a human
 *  reads, e.g. the list title and the empty-state message. */
export function visibleSearchTerm(term: string): string {
  return term.trimEnd();
}

/** Whether the current selection supports a scoped refresh — the cross-feed
 *  saved views (favorites/kept) don't map to any feed scope, so they can't. */
export function canScopedRefresh(s: Selection): boolean {
  return s.kind === 'all' || s.kind === 'tag' || s.kind === 'subscription';
}

/** A view that shows one logical stream rather than an aggregation of feeds: a
 *  single subscription, or the ranked for-you list. Such a view carries a "last
 *  refreshed" label and never collapses same-source runs into a group widget —
 *  that would hide entries and disrupt their order. */
export function isSingleStreamView(s: Selection): boolean {
  return s.kind === 'subscription' || s.kind === 'for-you';
}

/** Every query parameter that names which list is on screen. A navigation
 *  that changes the list must clear all of them, not just the ones a
 *  template happens to mention — `selectionFromParams` gives `q` priority
 *  over `view`/`tag`/`subscription`, so a leftover `q` the caller forgot to
 *  null silently wins and strands the user in search results (#408). */
const SELECTION_PARAM_NAMES = ['view', 'tag', 'subscription', 'entry', 'q'] as const;

/** The vocabulary as a type. `selectionParam` below accepts only these
 *  literals, so `selectionFromParams` cannot read a URL parameter into the
 *  selection without that name already being part of `SELECTION_PARAM_NAMES`
 *  — add a sixth selection parameter, wire it into `Selection` and a
 *  template, but forget it here, and the `selectionParam(p, 'foo')` call
 *  fails to compile instead of silently reading `null` forever (the #408
 *  failure mode this file exists to close). `unread` deliberately reads
 *  through plain `ParamMap.get` instead: it refines the current list, it
 *  does not choose which list is shown, so it is not part of this
 *  vocabulary and a navigation must not clear it. */
type SelectionParamName = (typeof SELECTION_PARAM_NAMES)[number];

/** The only way `selectionFromParams` may pull a selection-identity value
 *  out of the URL. Its parameter type is `SelectionParamName`, so this is
 *  where the compile error in the comment above actually fires. */
function selectionParam(p: ParamMap, name: SelectionParamName): string | null {
  return p.get(name);
}

type SelectionParamValue = string | number | null;
export type SelectionParams = Record<SelectionParamName, SelectionParamValue>;

/** Results handed out by `selectionQueryParams`, keyed by the argument that
 *  produced them.
 *
 *  Most callers are `[queryParams]` bindings in templates — one per sidebar
 *  feed, per tag, per source pill on every row. Angular caches a literal
 *  object in a binding, but NOT the result of a function call, so without
 *  this each of those allocated a fresh object on every change-detection
 *  pass, and `RouterLink` saw a changed input and rebuilt its href each time.
 *  Change detection runs on every scroll frame here, and the arguments are
 *  drawn from a bounded set (the vocabulary's four fixed views plus one entry
 *  per tag and per subscription), so caching on the argument returns a stable
 *  reference and the link work happens once. */
const selectionParamsCache = new Map<string, SelectionParams>();

/** Build a `[queryParams]` object that selects exactly one list: the given
 *  params are set, every other name in the selection vocabulary is nulled.
 *  Callers state only what they set — a new vocabulary entry only needs to
 *  be added here, not at every call site.
 *
 *  The returned object is shared between callers that pass equal arguments
 *  and must be treated as read-only; `RouterLink` only reads it. */
export function selectionQueryParams(set: Partial<SelectionParams>): SelectionParams {
  const key = JSON.stringify(SELECTION_PARAM_NAMES.map((name) => set[name] ?? null));
  const cached = selectionParamsCache.get(key);
  if (cached !== undefined) return cached;

  const cleared = Object.fromEntries(
    SELECTION_PARAM_NAMES.map((name) => [name, null]),
  ) as SelectionParams;
  const params = { ...cleared, ...set };
  // A search term is unbounded — one distinct key per term the user tries —
  // and that call site is a single navigation rather than a template binding,
  // so it gains nothing from the cache and must not be allowed to grow it.
  if (set.q == null) selectionParamsCache.set(key, params);

  return params;
}

/** The whole-word trailing space is the search signal (#408 follow-up), so a
 *  saved whole-word search reconstructs it when it navigates. */
export function savedSearchTerm(term: string, wholeWord: boolean): string {
  return wholeWord ? `${term} ` : term;
}

/** The query params that open a saved search, reusing the existing `q` search
 *  selection kind. */
export function savedSearchParams(term: string, wholeWord: boolean): SelectionParams {
  return selectionQueryParams({ q: savedSearchTerm(term, wholeWord) });
}

export function selectionFromParams(p: ParamMap): {
  selection: Selection;
  entryId: number | null;
} {
  const view = selectionParam(p, 'view');
  const tag = posInt(selectionParam(p, 'tag'));
  const subscription = posInt(selectionParam(p, 'subscription'));
  // unread refines the current list rather than choosing it, so it is not
  // part of the selection vocabulary above and is read directly.
  const unread = p.get('unread') !== '0';
  // The entry param is an id or an id-prefixed slug ("514-some-title").
  const entryId = entryIdFromParam(selectionParam(p, 'entry'));

  // Not a plain trim(): a trailing space is the whole-word-match signal
  // (#408 follow-up) and must survive into `Selection.term`, so only the
  // MEANINGLESS whitespace — leading, and runs collapsed between terms — is
  // removed here.
  const term = normalizeSearchInput(selectionParam(p, 'q') ?? '');
  if (isSearchableTerm(term)) {
    // A search is its own view over every subscription, so a tag or feed
    // parameter left in the URL by hand is ignored rather than combined.
    return { selection: { kind: 'search', id: null, unread: false, term }, entryId };
  }

  let selection: Selection;
  if (view === 'favorites' || view === 'kept' || view === 'viewed' || view === 'for-you') {
    selection = { kind: view, id: null, unread: false };
  } else if (subscription != null) {
    selection = { kind: 'subscription', id: subscription, unread };
  } else if (tag != null) {
    selection = { kind: 'tag', id: tag, unread };
  } else {
    selection = { kind: 'all', id: null, unread };
  }
  return { selection, entryId };
}

/** The list a URL names with any search set aside: the list a search is layered
 *  over, and the one that clearing the search returns to. `selectionFromParams`
 *  gives a searchable `q` priority over `view`/`tag`/`subscription`, so the
 *  covered list is invisible in the resulting selection even though the URL
 *  still carries it (#542). Reading it takes knowing that `q` alone makes a
 *  search, which is this file's knowledge rather than a caller's. */
export function listSelectionFrom(params: Params): Selection {
  return selectionFromParams(convertToParamMap({ ...params, q: null })).selection;
}

export function queryFromSelection(s: Selection): EntryQuery {
  switch (s.kind) {
    case 'favorites':
      return { view: 'favorites' };
    case 'kept':
      return { view: 'kept' };
    case 'viewed':
      return { view: 'viewed' };
    case 'for-you':
      return { view: 'for-you' };
    case 'tag':
      return { view: s.unread ? 'unread' : 'all', tag: s.id ?? undefined };
    case 'subscription':
      return { view: s.unread ? 'unread' : 'all', subscription: s.id ?? undefined };
    case 'all':
      return { view: s.unread ? 'unread' : 'all' };
    case 'search':
      return { view: 'all', q: s.term };
  }
}

/** What "Mark all read" applies to for a selection, or null where the action
 *  does not apply. A discriminated union rather than one bag with optional
 *  fields: the id and the term never coexist, and a bag would make every
 *  consumer assert its way back to the case it already switched on. */
export type MarkReadTarget =
  | { scope: Extract<MarkReadScope, 'all'> }
  | { scope: Exclude<MarkReadScope, 'all'>; id: number }
  | { scope: 'search'; term: string };

export function markReadTarget(s: Selection): MarkReadTarget | null {
  switch (s.kind) {
    case 'all':
      return { scope: 'all' };
    case 'tag':
      return s.id != null ? { scope: 'tag', id: s.id } : null;
    case 'subscription':
      return s.id != null ? { scope: 'feed', id: s.id } : null;
    case 'search':
      return s.term ? { scope: 'search', term: s.term } : null;
    default:
      return null;
  }
}

function posInt(v: string | null): number | null {
  if (v == null) return null;
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}
