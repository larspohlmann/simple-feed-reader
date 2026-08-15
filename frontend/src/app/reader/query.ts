// src/app/reader/query.ts
import { ParamMap } from '@angular/router';
import { EntryQuery, MarkReadScope } from './models';
import { entryIdFromParam } from './slug';

export interface Selection {
  kind: 'all' | 'tag' | 'subscription' | 'favorites' | 'kept' | 'for-you' | 'search';
  id: number | null;
  unread: boolean;
  /** Only a search carries one. Part of the list's identity, so it belongs to
   *  the selection rather than to a service beside it. */
  term?: string;
}

/** The shortest term the backend will accept. A shorter one is not an error
 *  here — it is a half-typed word, so the URL simply carries no search yet. */
export const MIN_SEARCH_LENGTH = 3;

/** Strips leading whitespace and collapses inner runs to a single space, but
 *  — unlike `String.trim()` — keeps exactly one trailing space when the raw
 *  input ended in whitespace. The server reads a trailing space as "match
 *  whole words only" versus substring matching, one mode for the whole query
 *  (#408 follow-up); a plain `trim()` here would destroy that signal before
 *  it ever reaches the request. Shared by the field's settled-emission path
 *  and by `selectionFromParams`, the two places a raw `q` value is turned
 *  into a term — both must agree on what "the term" is. */
export function normalizeSearchInput(raw: string): string {
  const hasTrailingSpace = /\s$/.test(raw);
  const collapsed = raw.trim().replace(/\s+/g, ' ');
  if (collapsed.length === 0) return '';
  return hasTrailingSpace ? `${collapsed} ` : collapsed;
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
type SelectionParams = Record<SelectionParamName, SelectionParamValue>;

/** Build a `[queryParams]` object that selects exactly one list: the given
 *  params are set, every other name in the selection vocabulary is nulled.
 *  Callers state only what they set — a new vocabulary entry only needs to
 *  be added here, not at every call site. */
export function selectionQueryParams(set: Partial<SelectionParams>): SelectionParams {
  const cleared = Object.fromEntries(
    SELECTION_PARAM_NAMES.map((name) => [name, null]),
  ) as SelectionParams;
  return { ...cleared, ...set };
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
  // The floor is measured on the trimmed value: 'ab ' is 3 raw characters
  // but 2 once the trailing space (not a real character of the word) is set
  // aside, so it must stay below MIN_SEARCH_LENGTH rather than search.
  if (term.trim().length >= MIN_SEARCH_LENGTH) {
    // A search is its own view over every subscription, so a tag or feed
    // parameter left in the URL by hand is ignored rather than combined.
    return { selection: { kind: 'search', id: null, unread: false, term }, entryId };
  }

  let selection: Selection;
  if (view === 'favorites' || view === 'kept' || view === 'for-you') {
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

export function queryFromSelection(s: Selection): EntryQuery {
  switch (s.kind) {
    case 'favorites':
      return { view: 'favorites' };
    case 'kept':
      return { view: 'kept' };
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

export function markReadTarget(s: Selection): { scope: MarkReadScope; id?: number } | null {
  switch (s.kind) {
    case 'all':
      return { scope: 'all' };
    case 'tag':
      return s.id != null ? { scope: 'tag', id: s.id } : null;
    case 'subscription':
      return s.id != null ? { scope: 'feed', id: s.id } : null;
    default:
      return null;
  }
}

function posInt(v: string | null): number | null {
  if (v == null) return null;
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}
