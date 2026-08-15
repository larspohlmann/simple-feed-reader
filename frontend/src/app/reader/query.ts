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

type SelectionParamValue = string | number | null;
type SelectionParams = Record<(typeof SELECTION_PARAM_NAMES)[number], SelectionParamValue>;

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
  const view = p.get('view');
  const tag = posInt(p.get('tag'));
  const subscription = posInt(p.get('subscription'));
  const unread = p.get('unread') !== '0';
  // The entry param is an id or an id-prefixed slug ("514-some-title").
  const entryId = entryIdFromParam(p.get('entry'));

  const term = (p.get('q') ?? '').trim();
  if (term.length >= MIN_SEARCH_LENGTH) {
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
