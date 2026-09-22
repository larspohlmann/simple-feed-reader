import { ParamMap, UrlMatchResult, UrlSegment } from '@angular/router';
import { Selection, selectionFromParams } from './query';

/**
 * The reader shell owns the root URL and the saved-search paths alike, through
 * one route config so the component is never torn down when the user moves
 * between a list and a saved search. `searches/saved/:slug` (slug may be the
 * literal "all") is the only path it consumes; everything else is query params.
 */
export function readerMatcher(segments: UrlSegment[]): UrlMatchResult | null {
  if (segments.length === 0) {
    return { consumed: [] };
  }
  if (segments.length === 3 && segments[0].path === 'searches' && segments[1].path === 'saved') {
    return { consumed: segments, posParams: { savedSearch: segments[2] } };
  }
  return null;
}

/** The leading integer of a slug ("42-climate" -> 42); null if absent. */
function idFromSlug(slug: string): number | null {
  const id = Number.parseInt(slug, 10);
  return Number.isNaN(id) ? null : id;
}

export function selectionFromRoute(
  pathParams: ParamMap,
  queryParams: ParamMap,
): { selection: Selection; entryId: number | null } {
  const savedSearch = pathParams.get('savedSearch');
  if (savedSearch === null) {
    return selectionFromParams(queryParams);
  }

  const unread = queryParams.get('unread') === '1';
  // The entry overlay composes on top of a saved-search path, so a merged
  // `?entry=` must still open the article rather than being dropped here.
  const { entryId } = selectionFromParams(queryParams);
  if (savedSearch === 'all') {
    return { selection: { kind: 'saved-searches', id: null, unread }, entryId };
  }
  return { selection: { kind: 'saved-search', id: idFromSlug(savedSearch), unread }, entryId };
}
