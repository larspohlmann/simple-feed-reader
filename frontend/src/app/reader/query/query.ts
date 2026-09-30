import { ParamMap, Params, convertToParamMap } from '@angular/router';
import { EntryQuery, ListOrder, MarkReadScope } from '../models';
import { entryIdFromParam } from './slug';

export interface Selection {
  kind:
    | 'all'
    | 'tag'
    | 'subscription'
    | 'favorites'
    | 'kept'
    | 'viewed'
    | 'for-you'
    | 'saved-searches'
    | 'saved-search'
    | 'search';
  id: number | null;
  unread: boolean;
  /** Only a search carries one. Part of the list's identity, so it belongs to
   *  the selection rather than to a service beside it. */
  term?: string;
  /** Absent means newest first; only a list `hasListOrder` accepts ever carries one. */
  order?: ListOrder;
}

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
function phraseWithin(term: string): string | null {
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

/** How a scoped refresh is keyed. Empty = all the user's due feeds; feedId =
 *  one feed; tagId = every feed carrying that tag. */
export interface RefreshScope {
  feedId?: number;
  tagId?: number;
}

/** Whether two selections name the same list. Selections are rebuilt from the
 *  route on every navigation, so they are never reference-equal. */
export function sameSelection(left: Selection, right: Selection): boolean {
  return (
    left.kind === right.kind &&
    left.id === right.id &&
    left.unread === right.unread &&
    left.term === right.term &&
    listOrderOf(left) === listOrderOf(right)
  );
}

export function isDirectSearch(selection: Selection): boolean {
  return selection.kind === 'search';
}

/** `Selection.term` with the trailing whole-word-match space stripped for
 *  display. The space must reach the server (`EntryQuery.q`) and stay in
 *  `Selection.term` (part of identity, see `sameSelection`) — this is only
 *  for human-readable strings like the list title. */
export function visibleSearchTerm(term: string): string {
  // A phrase shows as its bare inner text — the wrapping quotes are the mode
  // signal, surfaced by the "Phrase" pill instead, exactly as the trailing
  // whole-word space is dropped here and shown as its own pill (#702).
  return phraseWithin(term) ?? term.trimEnd();
}

/** Whether the list offers the "All posts / only unread" switch. Favorites,
 *  kept and viewed are already filters on entry state, so they do not. */
export function hasUnreadFilter(selection: Selection): boolean {
  return (
    canScopedRefresh(selection) ||
    selection.kind === 'for-you' ||
    selection.kind === 'saved-searches' ||
    selection.kind === 'saved-search' ||
    selection.kind === 'search'
  );
}

export function withUnreadPreference(selection: Selection, unreadOnly: boolean): Selection {
  return hasUnreadFilter(selection) ? { ...selection, unread: unreadOnly } : selection;
}

/** Whether the list offers the newest/oldest-first toggle; the ranked for-you
 *  feed has no date order to reverse. */
export function hasListOrder(selection: Selection): boolean {
  return selection.kind !== 'for-you';
}

export function listOrderOf(selection: Selection): ListOrder {
  return selection.order ?? 'newest';
}

/** Applies an order to a selection parsed from the URL, which never carries one. */
export function withListOrder(selection: Selection, order: ListOrder): Selection {
  return hasListOrder(selection) && order === 'oldest' ? { ...selection, order } : selection;
}

/** Where a list's order is remembered: per feed, tag, saved search and view, and
 *  once for every direct search, whose terms are throwaway. */
export function listOrderKey(selection: Selection): string | null {
  if (!hasListOrder(selection)) return null;
  if (selection.kind === 'search') return 'search';
  return selection.id === null ? selection.kind : `${selection.kind}:${selection.id}`;
}

/** Whether the current selection supports a scoped refresh — the cross-feed
 *  saved views (favorites/kept) don't map to any feed scope, so they can't. */
export function canScopedRefresh(selection: Selection): boolean {
  return selection.kind === 'all' || selection.kind === 'tag' || selection.kind === 'subscription';
}

/** A view that shows one logical stream, not an aggregation of feeds: a single
 *  subscription, or the ranked for-you list. Carries a "last refreshed" label and
 *  never collapses same-source runs into a group widget (would hide/reorder entries). */
export function isSingleStreamView(selection: Selection): boolean {
  return selection.kind === 'subscription' || selection.kind === 'for-you';
}

/** Every query parameter that names which list is on screen. A navigation that
 *  changes the list must clear all of them — `selectionFromParams` gives `q`
 *  priority, so a leftover `q` the caller forgot to null strands the user (#408). */
const SELECTION_PARAM_NAMES = ['view', 'tag', 'subscription', 'entry', 'q'] as const;

type SelectionParamName = (typeof SELECTION_PARAM_NAMES)[number];

/** The only way `selectionFromParams` may pull a selection-identity value
 *  out of the URL. Its parameter type is `SelectionParamName`, so this is
 *  where the compile error in the comment above actually fires. */
function selectionParam(paramMap: ParamMap, name: SelectionParamName): string | null {
  return paramMap.get(name);
}

type SelectionParamValue = string | number | null;
export type SelectionParams = Record<SelectionParamName, SelectionParamValue>;

/** Results handed out by `selectionQueryParams`, keyed by the argument that
 *  produced them. Most callers are `[queryParams]` template bindings (one per
 *  sidebar feed/tag/pill); Angular caches a literal object in a binding but not
 *  a function call's result, so without this each change-detection pass
 *  allocated a fresh object and `RouterLink` rebuilt its href every scroll
 *  frame. Arguments are drawn from a bounded set, so caching on the argument
 *  returns a stable reference and the link work happens once. */
const selectionParamsCache = new Map<string, SelectionParams>();

/** Build a `[queryParams]` object that selects exactly one list: the given
 *  params are set, every other vocabulary name is nulled — a new vocabulary
 *  entry only needs adding here, not at every call site. The returned object is
 *  shared between equal-argument callers and must be treated read-only. */
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

export function selectionFromParams(paramMap: ParamMap): {
  selection: Selection;
  entryId: number | null;
} {
  const view = selectionParam(paramMap, 'view');
  const tag = posInt(selectionParam(paramMap, 'tag'));
  const subscription = posInt(selectionParam(paramMap, 'subscription'));
  // The entry param is an id or an id-prefixed slug ("514-some-title").
  const entryId = entryIdFromParam(selectionParam(paramMap, 'entry'));

  // Not a plain trim(): a trailing space is the whole-word-match signal (#408
  // follow-up) and must survive into `Selection.term`, so only MEANINGLESS
  // whitespace — leading, and collapsed runs between terms — is removed here.
  const term = normalizeSearchInput(selectionParam(paramMap, 'q') ?? '');
  if (isSearchableTerm(term)) {
    // A `?q=` search is its own view over every subscription, so a tag or feed
    // parameter left in the URL by hand is ignored rather than combined.
    return { selection: { kind: 'search', id: null, unread: false, term }, entryId };
  }

  let selection: Selection;
  if (view === 'favorites' || view === 'kept' || view === 'viewed') {
    // Each of these IS a filter on entry state, so a second one would be a
    // contradiction: "kept, but only unread" is not a list this reader offers.
    selection = { kind: view, id: null, unread: false };
  } else if (view === 'for-you') {
    selection = { kind: 'for-you', id: null, unread: false };
  } else if (view === 'saved-searches') {
    selection = { kind: 'saved-searches', id: null, unread: false };
  } else if (subscription != null) {
    selection = { kind: 'subscription', id: subscription, unread: false };
  } else if (tag != null) {
    selection = { kind: 'tag', id: tag, unread: false };
  } else {
    selection = { kind: 'all', id: null, unread: false };
  }
  return { selection, entryId };
}

/** The list a URL names with any search set aside: the list a search is layered
 *  over, and the one that clearing the search returns to. `selectionFromParams`
 *  gives a searchable `q` priority, so the covered list is invisible in the
 *  resulting selection even though the URL still carries it (#542). */
export function listSelectionFrom(params: Params): Selection {
  return selectionFromParams(convertToParamMap({ ...params, q: null })).selection;
}

export function queryFromSelection(selection: Selection): EntryQuery {
  const query = viewQuery(selection);
  return listOrderOf(selection) === 'oldest' ? { ...query, order: 'oldest' } : query;
}

function viewQuery(selection: Selection): EntryQuery {
  return VIEW_QUERY[selection.kind](selection);
}

type SelectionKind = Selection['kind'];

const VIEW_QUERY: Record<SelectionKind, (selection: Selection) => EntryQuery> = {
  favorites: () => ({ view: 'favorites' }),
  kept: () => ({ view: 'kept' }),
  viewed: () => ({ view: 'viewed' }),
  // The one list whose unread filter is not a view of its own: the ranked
  // feed IS the view, so the filter travels beside it (#710).
  'for-you': (selection) => withUnread({ view: 'for-you' }, selection.unread),
  tag: (selection) => ({ view: listView(selection), tag: selection.id ?? undefined }),
  subscription: (selection) => ({
    view: listView(selection),
    subscription: selection.id ?? undefined,
  }),
  all: (selection) => ({ view: listView(selection) }),
  'saved-searches': (selection) => withUnread({ view: 'saved-searches' }, selection.unread),
  // A single saved search reads the membership table by id, not a live
  // query, so it carries the id and takes the unread refinement like any list.
  'saved-search': (selection) =>
    withUnread({ view: 'all', savedSearchId: selection.id ?? undefined }, selection.unread),
  search: (selection) => withUnread({ view: 'all', q: selection.term }, selection.unread),
};

function withUnread(query: EntryQuery, unread: boolean): EntryQuery {
  return unread ? { ...query, unread: true } : query;
}

function listView(selection: Selection): EntryQuery['view'] {
  return selection.unread ? 'unread' : 'all';
}

/** What "Mark all read" applies to for a selection, or null where the action
 *  doesn't apply. A discriminated union, not a bag with optional fields: id and
 *  term never coexist, and a bag would make every consumer re-assert the case. */
export type MarkReadTarget =
  | { scope: Extract<MarkReadScope, 'all'> }
  | { scope: Exclude<MarkReadScope, 'all'>; id: number }
  | { scope: 'search'; term: string }
  | { scope: 'for-you' }
  | { scope: 'saved-searches' }
  | { scope: 'saved-search'; id: number };

export function markReadTarget(selection: Selection): MarkReadTarget | null {
  return MARK_READ_TARGET[selection.kind]?.(selection) ?? null;
}

const MARK_READ_TARGET: Partial<
  Record<SelectionKind, (selection: Selection) => MarkReadTarget | null>
> = {
  all: () => ({ scope: 'all' }),
  tag: idScoped('tag'),
  subscription: idScoped('feed'),
  search: (selection) => (selection.term ? { scope: 'search', term: selection.term } : null),
  // No id and no term: the list is the reader's own ranked feed, so the
  // endpoint needs nothing beyond who is asking (#710).
  'for-you': () => ({ scope: 'for-you' }),
  'saved-searches': () => ({ scope: 'saved-searches' }),
  'saved-search': idScoped('saved-search'),
};

function idScoped(
  scope: Extract<MarkReadTarget, { id: number }>['scope'],
): (selection: Selection) => MarkReadTarget | null {
  return (selection) => (selection.id != null ? { scope, id: selection.id } : null);
}

function posInt(value: string | null): number | null {
  if (value == null) return null;
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}
