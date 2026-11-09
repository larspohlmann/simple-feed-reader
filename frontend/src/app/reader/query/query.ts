import { ParamMap, Params, convertToParamMap } from '@angular/router';
import { ListOrder } from '../models';
import { TrendingWindow } from '../trending/trending.models';
import { FeedGroupName, isFeedGroupName } from '../../core/feed-group';
import { entryIdFromParam } from './slug';
import { isSearchableTerm, normalizeSearchInput, phraseWithin } from './search-term';
import { isTrendingWindow } from '../trending/trending-window';
import {
  NetworkFeedKind,
  SocialNetworkName,
  isNetworkFeedKind,
  isSocialNetworkName,
} from '../../core/social-network';

/** A list that takes the unread switch and the newest/oldest order. */
interface FilteredList {
  unread: boolean;
  /** Absent means newest first. */
  order?: ListOrder;
}

/** A list of entry states: already a filter, so it takes only the order. */
interface StateList {
  order?: ListOrder;
}

/** What each kind of list carries beside its kind. */
interface SelectionFields {
  all: FilteredList;
  tag: FilteredList & { id: number };
  subscription: FilteredList & { id: number };
  network: FilteredList & { network: SocialNetworkName; feedKind?: NetworkFeedKind };
  group: FilteredList & { group: FeedGroupName };
  favorites: StateList;
  kept: StateList;
  shared: StateList;
  viewed: StateList;
  'for-you': { unread: boolean };
  trending: { window: TrendingWindow };
  'saved-searches': FilteredList;
  'saved-search': FilteredList & { id: number };
  search: FilteredList & { term: string };
}

export type SelectionKind = keyof SelectionFields;
export type SelectionOf<K extends SelectionKind> = { kind: K } & SelectionFields[K];
export type Selection = { [K in SelectionKind]: SelectionOf<K> }[SelectionKind];
export type FilteredSelection = Extract<Selection, { unread: boolean }>;
type OrderedSelection = Exclude<Selection, { kind: 'for-you' | 'trending' }>;

/** One arm per kind, each handed the selection already narrowed to that kind. */
export type BySelectionKind<R> = { [K in SelectionKind]: (selection: SelectionOf<K>) => R };

export function bySelectionKind<R>(table: BySelectionKind<R>, selection: Selection): R {
  return armFor(table, selection.kind)(selection);
}

// The selection's own kind picked the arm, so it fits; TypeScript cannot correlate the two (TS #30581).
function armFor<K extends SelectionKind, R>(
  table: BySelectionKind<R>,
  kind: K,
): (selection: SelectionOf<K>) => R {
  return table[kind];
}

/** Which feeds a refresh covers: all the user's due feeds, one feed, a tag's
 *  feeds, a network's feeds, a sidebar section's feeds, or the never-fetched ones. */
export type RefreshScope =
  | { kind: 'all' }
  | { kind: 'feed'; feedId: number }
  | { kind: 'tag'; tagId: number }
  | { kind: 'network'; network: SocialNetworkName; feedKind?: NetworkFeedKind }
  | { kind: 'group'; group: FeedGroupName }
  | { kind: 'neverFetched' };

/** Whether two selections name the same list. Selections are rebuilt from the
 *  route on every navigation, so they are never reference-equal. */
export function sameSelection(left: Selection, right: Selection): boolean {
  return (
    left.kind === right.kind &&
    identityKey(left) === identityKey(right) &&
    isUnreadOnly(left) === isUnreadOnly(right) &&
    listOrderOf(left) === listOrderOf(right)
  );
}

/** What tells two lists of one kind apart: the id, term, network, group or window the kind carries. */
export function identityKey(selection: Selection): string {
  return bySelectionKind(IDENTITY_KEY, selection);
}

const IDENTITY_KEY: BySelectionKind<string> = {
  all: () => '',
  tag: (selection) => String(selection.id),
  subscription: (selection) => String(selection.id),
  network: (selection) =>
    selection.feedKind ? `${selection.network}:${selection.feedKind}` : selection.network,
  group: (selection) => selection.group,
  favorites: () => '',
  kept: () => '',
  shared: () => '',
  viewed: () => '',
  'for-you': () => '',
  trending: (selection) => selection.window,
  'saved-searches': () => '',
  'saved-search': (selection) => String(selection.id),
  search: (selection) => selection.term,
};

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
 *  kept, shared, viewed and Trending carry no unread flag: they do not. */
export function hasUnreadFilter(selection: Selection): selection is FilteredSelection {
  return 'unread' in selection;
}

export function withUnreadPreference(selection: Selection, unreadOnly: boolean): Selection {
  return hasUnreadFilter(selection) ? { ...selection, unread: unreadOnly } : selection;
}

/** Whether the list shows only unread posts; never true for a list without the switch. */
export function isUnreadOnly(selection: Selection): boolean {
  return hasUnreadFilter(selection) && selection.unread;
}

/** The search on screen, trailing whole-word space included; empty outside a search. */
export function searchTermOf(selection: Selection): string {
  return selection.kind === 'search' ? selection.term : '';
}

/** The window Trending shows, or null on every other list. */
export function trendingWindowOf(selection: Selection): TrendingWindow | null {
  return selection.kind === 'trending' ? selection.window : null;
}

/** Whether the list offers the newest/oldest-first toggle; the ranked for-you
 *  and Trending lists have no date order to reverse. */
export function hasListOrder(selection: Selection): selection is OrderedSelection {
  return selection.kind !== 'for-you' && selection.kind !== 'trending';
}

export function listOrderOf(selection: Selection): ListOrder {
  return hasListOrder(selection) ? (selection.order ?? 'newest') : 'newest';
}

/** Applies an order to a selection parsed from the URL, which never carries one. */
export function withListOrder(selection: Selection, order: ListOrder): Selection {
  return hasListOrder(selection) && order === 'oldest' ? { ...selection, order } : selection;
}

/** Trending is a picture of the news, not an inbox: nothing in it is marked read in bulk (#2017). */
export function offersMarkAboveRead(selection: Selection): boolean {
  return selection.kind !== 'trending';
}

/** Where a list's order is remembered: per feed, tag, saved search and view, and
 *  once for every direct search, whose terms are throwaway. */
export function listOrderKey(selection: Selection): string | null {
  if (!hasListOrder(selection)) return null;
  if (selection.kind === 'search') return 'search';
  const identity = identityKey(selection);
  return identity === '' ? selection.kind : `${selection.kind}:${identity}`;
}

/** Whether the current selection supports a scoped refresh — the cross-feed
 *  saved views (favorites/kept) don't map to any feed scope, so they can't. */
export function canScopedRefresh(selection: Selection): boolean {
  return (
    selection.kind === 'all' ||
    selection.kind === 'tag' ||
    selection.kind === 'network' ||
    selection.kind === 'group' ||
    selection.kind === 'subscription'
  );
}

/** A view that shows one logical stream, not an aggregation of feeds: a single
 *  subscription, or a ranked list (for you, Trending). Carries a "last refreshed" label and
 *  never collapses same-source runs into a group widget (would hide/reorder entries). */
export function isSingleStreamView(selection: Selection): boolean {
  return (
    selection.kind === 'subscription' ||
    selection.kind === 'for-you' ||
    selection.kind === 'trending'
  );
}

/** Every query parameter that names which list is on screen. A navigation that
 *  changes the list must clear all of them — `selectionFromParams` gives `q`
 *  priority, so a leftover `q` the caller forgot to null strands the user (#408). */
const SELECTION_PARAM_NAMES = [
  'view',
  'tag',
  'network',
  'feedKind',
  'group',
  'subscription',
  'entry',
  'link',
  'q',
  'window',
] as const;

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

/** What the account brings to reading a URL: whether it offers Trending (D39), and the window a Trending link that
 *  names none opens (D40). */
export interface ViewContext {
  trending: boolean;
  trendingWindow: TrendingWindow;
}

/** What a URL names, and how the URL must change to say the same; null when it already does. */
export interface ParsedRoute {
  selection: Selection;
  entryId: number | null;
  urlCorrection: Params | null;
}

type ParsedView = Omit<ParsedRoute, 'entryId'>;

export function selectionFromParams(paramMap: ParamMap, context: ViewContext): ParsedRoute {
  // The entry param is an id or an id-prefixed slug ("514-some-title").
  const entryId = entryIdFromParam(selectionParam(paramMap, 'entry'));

  // Not a plain trim(): a trailing space is the whole-word-match signal (#408
  // follow-up) and must survive into `Selection.term`, so only MEANINGLESS
  // whitespace — leading, and collapsed runs between terms — is removed here.
  const term = normalizeSearchInput(selectionParam(paramMap, 'q') ?? '');
  if (isSearchableTerm(term)) {
    // A `?q=` search is its own view over every subscription, so a tag or feed
    // parameter left in the URL by hand is ignored rather than combined.
    const selection: Selection = { kind: 'search', unread: false, term };
    return { selection, entryId, urlCorrection: null };
  }

  return { ...viewFromParams(paramMap, context), entryId };
}

/** The views of entry states: no feed, no unread switch. */
const STATE_VIEWS = ['favorites', 'kept', 'shared', 'viewed'] as const;

/** The views that name no feed but take the unread switch. */
const FILTERED_VIEWS = ['for-you', 'saved-searches'] as const;

function isViewIn<View extends string>(views: readonly View[], view: string | null): view is View {
  return (views as readonly (string | null)[]).includes(view);
}

function viewFromParams(paramMap: ParamMap, context: ViewContext): ParsedView {
  const view = selectionParam(paramMap, 'view');
  if (view === 'trending') return trendingFromParams(paramMap, context);
  if (isViewIn(STATE_VIEWS, view)) return { selection: { kind: view }, urlCorrection: null };
  if (isViewIn(FILTERED_VIEWS, view)) {
    return { selection: { kind: view, unread: false }, urlCorrection: null };
  }
  return { selection: feedScopedSelection(paramMap), urlCorrection: null };
}

/** Switched off, Trending opens All items, as an unknown view does, and leaves the URL. A URL naming no window
 *  (or an unknown one) opens the account's window and gains it. */
function trendingFromParams(paramMap: ParamMap, context: ViewContext): ParsedView {
  if (!context.trending) {
    return {
      selection: feedScopedSelection(paramMap),
      urlCorrection: { view: null, window: null },
    };
  }
  const named = selectionParam(paramMap, 'window');
  const window = isTrendingWindow(named) ? named : context.trendingWindow;
  return {
    selection: { kind: 'trending', window },
    urlCorrection: named === window ? null : { window },
  };
}

/** The lists drawn from feeds: one subscription, a tag, a network's feeds, a feed group, or all of them. */
function feedScopedSelection(paramMap: ParamMap): Selection {
  const subscription = posInt(selectionParam(paramMap, 'subscription'));
  if (subscription != null) return { kind: 'subscription', id: subscription, unread: false };
  const tag = posInt(selectionParam(paramMap, 'tag'));
  if (tag != null) return { kind: 'tag', id: tag, unread: false };
  const network = selectionParam(paramMap, 'network');
  if (isSocialNetworkName(network))
    return networkSelection(network, selectionParam(paramMap, 'feedKind'));
  const group = selectionParam(paramMap, 'group');
  if (isFeedGroupName(group)) return { kind: 'group', unread: false, group };
  return { kind: 'all', unread: false };
}

function networkSelection(network: SocialNetworkName, feedKind: string | null): Selection {
  const selection: SelectionOf<'network'> = { kind: 'network', unread: false, network };
  return isNetworkFeedKind(feedKind) ? { ...selection, feedKind } : selection;
}

/** The list a URL names with any search set aside: the list a search is layered
 *  over, and the one that clearing the search returns to. `selectionFromParams`
 *  gives a searchable `q` priority, so the covered list is invisible in the
 *  resulting selection even though the URL still carries it (#542). */
export function listSelectionFrom(params: Params, context: ViewContext): Selection {
  return selectionFromParams(convertToParamMap({ ...params, q: null }), context).selection;
}

function posInt(value: string | null): number | null {
  if (value == null) return null;
  const parsed = Number(value);
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}
